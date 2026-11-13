<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Twig\Error\SyntaxError;
use Twig\Source;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Shared\Exception\DomainException;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Infrastructure\Cache\PageCache;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Theme\ColorValueValidator;
use ValeApp\Infrastructure\Theme\ThemeManager;
use ValeApp\Infrastructure\Translation\Translator;
use ZipArchive;

final class ThemeController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly ThemeManager $themeManager,
        private readonly DbalSettingsRepository $settings,
        private readonly SessionManager $session,
        private readonly Translator $translator,
        private readonly ContentTypeRepositoryInterface $contentTypes,
        private readonly PostRepositoryInterface $posts,
        private readonly ?PageCache $pageCache = null,
    ) {
    }

    /**
     * POST /admin/themes/preview — enable preview for a theme.
     */
    public function preview(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $slug = trim((string) ($body['theme'] ?? ''));

        if (!$this->isValidThemeSlug($slug)) {
            $this->session->set('flash_error', 'No theme specified.');
            return $response->withHeader('Location', admin_url('/themes'))->withStatus(302);
        }

        // Validate theme exists
        $themeJson = base_path('themes/' . $slug . '/theme.json');
        if (!file_exists($themeJson)) {
            $this->session->set('flash_error', 'Theme not found.');
            return $response->withHeader('Location', admin_url('/themes'))->withStatus(302);
        }

        $this->session->set('preview_theme', $slug);

        $this->session->set('flash_success', $this->translator->get('Theme ":name" is now in preview mode.', [':name' => $slug]));
        return $response->withHeader('Location', site_url('/'))->withStatus(302);
    }

    /**
     * GET /themes/stop-preview — stop theme preview.
     */
    public function stopPreview(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->session->remove('preview_theme');
        return $response->withHeader('Location', admin_url('/themes'))->withStatus(302);
    }

    /**
     * GET /admin/themes — list all available themes.
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $themes      = $this->themeManager->discoverThemes();
        $activeTheme = $this->themeManager->getActiveTheme();

        return $this->twig->render($response, 'admin/themes/index.twig', [
            'themes'       => $themes,
            'active_theme' => $activeTheme,
        ]);
    }

    /**
     * GET /admin/themes/{slug}/edit — show template editor for a theme.
     */
    public function edit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $slug      = $args['slug'] ?? '';
        if (!$this->isValidThemeSlug($slug)) {
            $this->session->set('flash_error', 'Theme not found.');
            return $response->withHeader('Location', admin_url('/themes'))->withStatus(302);
        }
        $themePath = base_path('themes/' . $slug);

        if (!is_dir($themePath)) {
            $this->session->set('flash_error', 'Theme not found.');
            return $response->withHeader('Location', admin_url('/themes'))->withStatus(302);
        }

        $cptEnabled   = $this->settings->get('custom_content_types_enabled', '1') === '1';
        $customTypes  = $cptEnabled ? $this->contentTypes->findActiveCustom() : [];
        $cptSlugs     = [];
        $contentTypes = [];
        foreach ($customTypes as $type) {
            $typeSlug   = (string) $type->getSlug();
            $cptSlugs[] = $typeSlug;

            $placeholders = [
                '{{ site_name }}',
                '{{ site_url }}',
                '{{ post.title }}',
                '{{ post.body }}',
                '{{ post.body|shortcodes(post)|raw }}',
                '{{ post.excerpt }}',
                '{{ post.publishedAt|date }}',
                '{{ post.metaTitle }}',
                '{{ post.metaDescription }}',
                '{{ type.name }}',
                '{{ type.slug }}',
                '{{ type.routePrefix }}',
            ];

            foreach ($type->getFieldSchema() as $field) {
                $placeholders[] = '{{' . $field['name'] . '}}';
            }

            $contentTypes[] = [
                'name'         => (string) $type->getName(),
                'slug'         => $typeSlug,
                'has_template' => file_exists(base_path('themes/' . $slug . '/templates/' . $typeSlug . '.twig')),
                'placeholders' => $placeholders,
            ];
        }

        $templatesDir = $themePath . '/templates';
        $isWritable   = is_writable(is_dir($templatesDir) ? $templatesDir : $themePath);

        $templates = $this->getThemeTemplates($slug, $cptSlugs);

        return $this->twig->render($response, 'admin/themes/edit.twig', [
            'theme_slug'       => $slug,
            'templates'        => $templates,
            'is_writable'      => $isWritable,
            'is_bundled_theme' => $this->themeManager->isBundledTheme($slug),
            'placeholders'     => $this->getPagePlaceholders(),
            'content_types'    => $contentTypes,
        ]);
    }

    /**
     * GET /admin/themes/{slug}/templates/get — AJAX: return template content.
     */
    public function getTemplate(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $slug        = $args['slug'] ?? '';
        if (!$this->isValidThemeSlug($slug)) {
            return $this->jsonResponse($response, ['ok' => false, 'error' => 'Invalid theme'], 400);
        }
        $queryParams = $request->getQueryParams();
        $file        = $queryParams['file'] ?? '';

        if (!preg_match('/^[a-z0-9\/_-]+\.twig$/i', $file)) {
            return $this->jsonResponse($response, ['ok' => false, 'error' => 'Invalid file name'], 400);
        }

        $filePath = base_path('themes/' . $slug . '/templates/' . $file);
        if (!file_exists($filePath)) {
            return $this->jsonResponse($response, ['ok' => false, 'error' => 'File not found'], 404);
        }

        return $this->jsonResponse($response, [
            'ok'      => true,
            'content' => file_get_contents($filePath),
        ]);
    }

    /**
     * POST /admin/themes/{slug}/templates/save — AJAX: save template content.
     */
    public function saveTemplate(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $slug    = $args['slug'] ?? '';
        if (!$this->isValidThemeSlug($slug)) {
            return $this->jsonResponse($response, ['ok' => false, 'error' => 'Invalid theme'], 400);
        }
        $body    = (array) $request->getParsedBody();
        $file    = $body['file']    ?? '';
        $content = $body['content'] ?? '';

        if (!preg_match('/^[a-z0-9\/_-]+\.twig$/i', $file)) {
            return $this->jsonResponse($response, ['ok' => false, 'error' => 'Invalid file name'], 400);
        }

        // Simple Twig parse check
        try {
            $this->twig->getEnvironment()->parse($this->twig->getEnvironment()->tokenize(new Source($content, $file)));
        } catch (SyntaxError $e) {
            return $this->jsonResponse($response, ['ok' => false, 'error' => 'Twig Syntax Error: ' . $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return $this->jsonResponse($response, ['ok' => false, 'error' => 'Parse Error: ' . $e->getMessage()], 422);
        }

        $filePath = base_path('themes/' . $slug . '/templates/' . $file);
        if (!is_writable(dirname($filePath)) || (file_exists($filePath) && !is_writable($filePath))) {
            return $this->jsonResponse($response, ['ok' => false, 'error' => 'File or directory is not writable.'], 403);
        }

        $content = $this->expandFieldShortcuts($file, $content);

        file_put_contents($filePath, $content);
        $this->invalidateTemplateCache($file, $filePath);
        $this->pageCache?->flush();

        return $this->jsonResponse($response, ['ok' => true]);
    }

    /**
     * Expand bare {{ fieldname }} placeholders into {{ post.getMetaValue('fieldname', '') }}
     * for custom content type templates. Unknown placeholders are left untouched.
     */
    private function expandFieldShortcuts(string $file, string $content): string
    {
        if (str_starts_with($file, 'page-templates/') || !str_contains($content, '{{')) {
            return $content;
        }

        $typeSlug = basename($file, '.twig');

        try {
            $slug = new Slug($typeSlug);
        } catch (DomainException) {
            return $content;
        }

        $contentType = $this->contentTypes->findBySlug($slug);
        if ($contentType === null || $contentType->isSystem() || !$contentType->isActive()) {
            return $content;
        }

        foreach ($contentType->getFieldSchema() as $field) {
            $name = $field['name'] ?? '';
            if ($name === '') {
                continue;
            }
            $replacement = "{{ post.getMetaValue('" . str_replace("'", "\\'", $name) . "', '') }}";
            $content     = preg_replace(
                '/\{\{\s*' . preg_quote($name, '/') . '\s*\}\}/',
                $replacement,
                $content
            );
        }

        return $content;
    }

    /**
     * Remove only the compiled cache for the template Twig would actually load
     * for this logical name, leaving every other template's cache untouched.
     */
    private function invalidateTemplateCache(string $name, string $filePath): void
    {
        $environment = $this->twig->getEnvironment();
        $loader      = $environment->getLoader();

        if (!$loader->exists($name)) {
            return;
        }

        $sourcePath = $loader->getSourceContext($name)->getPath();
        if ($sourcePath === '' || realpath($sourcePath) !== realpath($filePath)) {
            return;
        }

        $environment->removeCache($name);
    }

    /**
     * POST /admin/themes/{slug}/templates/create — create a new page template or CPT template.
     */
    public function createTemplate(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $slug       = $args['slug'] ?? '';
        if (!$this->isValidThemeSlug($slug)) {
            $this->session->set('flash_error', 'Invalid theme.');
            return $response->withHeader('Location', admin_url('/themes'))->withStatus(302);
        }
        $body       = (array) $request->getParsedBody();
        $name       = trim($body['name'] ?? '');
        $type       = trim($body['type'] ?? '');
        $extendPost = ($body['extend_post'] ?? '0') === '1';

        if ($type !== '') {
            return $this->createContentTemplate($slug, $type, $extendPost, $response);
        }

        if (!preg_match('/^[a-z0-9_-]+$/i', $name)) {
            $this->session->set('flash_error', 'Invalid template name. Use alphanumeric characters, dashes, and underscores.');
            return $response->withHeader('Location', admin_url("/themes/$slug/edit"))->withStatus(302);
        }

        $fileName = $name . '.twig';
        $dirPath  = base_path('themes/' . $slug . '/templates/page-templates');
        if (!is_dir($dirPath)) {
            if (!mkdir($dirPath, 0755, true) && !is_dir($dirPath)) {
                $this->session->set('flash_error', 'Failed to create page-templates directory.');
                return $response->withHeader('Location', admin_url("/themes/$slug/edit"))->withStatus(302);
            }
        }

        $filePath = $dirPath . '/' . $fileName;
        if (file_exists($filePath)) {
            $this->session->set('flash_error', 'Template already exists.');
            return $response->withHeader('Location', admin_url("/themes/$slug/edit"))->withStatus(302);
        }

        $description = ucwords(str_replace(['-', '_'], ' ', $name));
        $content     = "{# DESCRIPTION: $description #}\n";
        file_put_contents($filePath, $content);
        $this->invalidateTemplateCache('page-templates/' . $fileName, $filePath);

        $this->session->set('flash_success', "Template \"$fileName\" created.");
        return $response->withHeader('Location', admin_url("/themes/$slug/edit?file=page-templates/$fileName"))->withStatus(302);
    }

    private function createContentTemplate(string $themeSlug, string $typeSlug, bool $extendPost, ResponseInterface $response): ResponseInterface
    {
        $contentType = $this->contentTypes->findBySlug(new Slug($typeSlug));
        if ($contentType === null || $contentType->isSystem() || !$contentType->isActive()) {
            $this->session->set('flash_error', 'Invalid content type.');
            return $response->withHeader('Location', admin_url("/themes/$themeSlug/edit"))->withStatus(302);
        }

        $fileName = (string) $contentType->getSlug() . '.twig';
        $dirPath  = base_path('themes/' . $themeSlug . '/templates');
        if (!is_dir($dirPath)) {
            if (!mkdir($dirPath, 0755, true) && !is_dir($dirPath)) {
                $this->session->set('flash_error', 'Failed to create templates directory.');
                return $response->withHeader('Location', admin_url("/themes/$themeSlug/edit"))->withStatus(302);
            }
        }

        $filePath = $dirPath . '/' . $fileName;
        if (file_exists($filePath)) {
            $this->session->set('flash_error', 'Template already exists.');
            return $response->withHeader('Location', admin_url("/themes/$themeSlug/edit"))->withStatus(302);
        }

        $content = "{# DESCRIPTION: " . $contentType->getName() . " #}\n";

        if ($extendPost) {
            $content .= "{% extends \"post.twig\" %}\n";
        } else {
            $themePost = base_path('themes/' . $themeSlug . '/templates/post.twig');
            $source    = file_exists($themePost) ? $themePost : base_path('resources/views/post.twig');
            $content   .= @file_get_contents($source) ?: '';
        }

        file_put_contents($filePath, $content);
        $this->invalidateTemplateCache($fileName, $filePath);

        $this->session->set('flash_success', 'Template "' . $fileName . '" created.');
        return $response->withHeader('Location', admin_url("/themes/$themeSlug/edit?file=$fileName"))->withStatus(302);
    }

    /**
     * POST /admin/themes/{slug}/templates/delete — delete a CPT template or Page template.
     */
    public function deleteTemplate(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $slug = $args['slug'] ?? '';
        if (!$this->isValidThemeSlug($slug)) {
            $this->session->set('flash_error', 'Invalid theme.');
            return $response->withHeader('Location', admin_url('/themes'))->withStatus(302);
        }
        $body = (array) $request->getParsedBody();
        $file = trim((string) ($body['file'] ?? ''));

        if ($slug === '' || $file === '') {
            $this->session->set('flash_error', 'Invalid request.');
            return $response->withHeader('Location', admin_url("/themes/$slug/edit"))->withStatus(302);
        }

        if (!preg_match('/^[a-z0-9\/_-]+\.twig$/i', $file)) {
            $this->session->set('flash_error', 'Invalid file name.');
            return $response->withHeader('Location', admin_url("/themes/$slug/edit"))->withStatus(302);
        }

        $baseDir  = base_path('themes/' . $slug . '/templates');
        $filePath = realpath($baseDir . '/' . $file);
        $realBase = realpath($baseDir);

        if ($filePath === false || $realBase === false || !$this->isWithinDirectory($filePath, $realBase)) {
            $this->session->set('flash_error', 'Invalid file path.');
            return $response->withHeader('Location', admin_url("/themes/$slug/edit"))->withStatus(302);
        }

        if (!file_exists($filePath)) {
            $this->session->set('flash_error', 'File not found.');
            return $response->withHeader('Location', admin_url("/themes/$slug/edit"))->withStatus(302);
        }

        if (str_starts_with($file, 'page-templates/')) {
            $fileName = basename($file);
            $tplName  = basename($file, '.twig');

            // 1. Core template check
            $corePath = base_path('resources/views/page-templates/' . $fileName);
            if (file_exists($corePath)) {
                $this->session->set('flash_error', $this->translator->get('Core page templates cannot be deleted.'));
                return $response->withHeader('Location', admin_url("/themes/$slug/edit"))->withStatus(302);
            }

            // 2. In-use check
            if ($this->posts->countByPageTemplate($tplName) > 0) {
                $this->session->set('flash_error', $this->translator->get('This template is in use and cannot be deleted.'));
                return $response->withHeader('Location', admin_url("/themes/$slug/edit"))->withStatus(302);
            }
        } else {
            $fileName    = basename($file, '.twig');
            $customTypes = $this->contentTypes->findActiveCustom();
            $cptSlugs    = array_map(fn ($t) => (string) $t->getSlug(), $customTypes);

            if (!in_array($fileName, $cptSlugs, true)) {
                $this->session->set('flash_error', 'Only custom content templates can be deleted.');
                return $response->withHeader('Location', admin_url("/themes/$slug/edit"))->withStatus(302);
            }
        }

        $this->invalidateTemplateCache($file, $filePath);
        $this->pageCache?->flush();
        @unlink($filePath);
        $this->session->set('flash_success', $this->translator->get('Template ":name" deleted.', [':name' => basename($file)]));
        return $response->withHeader('Location', admin_url("/themes/$slug/edit"))->withStatus(302);
    }

    private function getThemeTemplates(string $slug, array $cptSlugs = []): array
    {
        $baseDir   = base_path('themes/' . $slug . '/templates');
        $templates = [];
        $isBundled = $this->themeManager->isBundledTheme($slug);

        if (!is_dir($baseDir)) {
            return [];
        }

        $cptSlugSet = array_flip($cptSlugs);

        foreach (glob($baseDir . '/*.twig') as $file) {
            $content     = @file_get_contents($file) ?: '';
            $description = '';
            if (preg_match('/\{#\s*DESCRIPTION:\s*(.+?)\s*#\}/i', $content, $m)) {
                $description = trim($m[1]);
            }

            $name  = basename($file, '.twig');
            $type  = (isset($cptSlugSet[$name]) ? 'cpt-template' : 'main');

            $templates[] = [
                'name'        => basename($file),
                'path'        => basename($file),
                'type'        => $type,
                'description' => $description,
                'is_system'   => $isBundled,
            ];
        }

        $ptDir = $baseDir . '/page-templates';
        if (is_dir($ptDir)) {
            foreach (glob($ptDir . '/*.twig') as $file) {
                $content     = @file_get_contents($file) ?: '';
                $description = '';
                if (preg_match('/\{#\s*DESCRIPTION:\s*(.+?)\s*#\}/i', $content, $m)) {
                    $description = trim($m[1]);
                }

                $tplName    = basename($file, '.twig');
                $usageCount = $this->posts->countByPageTemplate($tplName);
                $isCore     = file_exists(base_path('resources/views/page-templates/' . basename($file)));

                $templates[] = [
                    'name'        => basename($file),
                    'path'        => 'page-templates/' . basename($file),
                    'type'        => 'page-template',
                    'description' => $description,
                    'in_use'      => $usageCount > 0,
                    'is_core'     => $isCore,
                    'is_system'   => $isBundled && $isCore,
                ];
            }
        }

        return $templates;
    }

    private function getPagePlaceholders(): array
    {
        return [
            'common' => [
                '{{ site_name }}',
                '{{ site_url }}',
            ],
            'page' => [
                '{{ page.title }}',
                '{{ page.body }}',
                '{{ page.excerpt }}',
                '{{ page.author.name }}',
                '{{ page.published_at|date }}',
                '{{ page.body|shortcodes(page)|raw }}',
            ],
        ];
    }

    private function jsonResponse(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    /**
     * Check that a resolved path lives inside the given base directory.
     * Separators are normalized because realpath() returns backslashes on Windows.
     */
    private function isWithinDirectory(string $path, string $baseDir): bool
    {
        $path    = str_replace('\\', '/', $path);
        $baseDir = rtrim(str_replace('\\', '/', $baseDir), '/');

        return str_starts_with($path, $baseDir . '/');
    }

    /**
     * A theme slug must be a single safe path segment: traversal sequences,
     * separators and markup characters are impossible by construction.
     */
    private function isValidThemeSlug(string $slug): bool
    {
        return preg_match('/^[a-z0-9_-]+$/i', $slug) === 1;
    }

    /**
     * POST /admin/themes/activate — switch the active theme.
     */
    public function activate(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $slug = trim((string) ($body['theme'] ?? ''));

        if (!$this->isValidThemeSlug($slug)) {
            $this->session->set('flash_error', 'No theme specified.');
            return $response->withHeader('Location', admin_url('/themes'))->withStatus(302);
        }

        // Validate: theme directory + theme.json must exist
        $themeJson = base_path('themes/' . $slug . '/theme.json');

        if (!file_exists($themeJson)) {
            $this->session->set('flash_error', 'Theme not found: ' . htmlspecialchars($slug, ENT_QUOTES));
            return $response->withHeader('Location', admin_url('/themes'))->withStatus(302);
        }

        $this->settings->set('active_theme', $slug, 'theme');
        $this->pageCache?->flush();

        // Clear any preview session so the activated theme takes effect
        $this->session->remove('preview_theme');

        $this->session->set('flash_success', $this->translator->get('Theme ":name" activated.', [':name' => htmlspecialchars($slug, ENT_QUOTES)]));
        return $response->withHeader('Location', admin_url('/themes'))->withStatus(302);
    }

    /**
     * GET /admin/themes/upload — show upload form.
     */
    public function upload(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->twig->render($response, 'admin/themes/upload.twig');
    }

    /**
     * POST /admin/themes/upload — handle ZIP upload.
     */
    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $uploadedFiles = $request->getUploadedFiles();
        $zipFile       = $uploadedFiles['theme_zip'] ?? null;
        $body          = (array) $request->getParsedBody();
        $overwrite     = ($body['overwrite'] ?? '') === '1';

        if (!$zipFile || $zipFile->getError() !== UPLOAD_ERR_OK) {
            $this->session->set('flash_error', $this->translator->get('Please upload a valid ZIP file.'));
            return $response->withHeader('Location', admin_url('/themes/upload'))->withStatus(302);
        }

        if (!extension_loaded('zip')) {
            $this->session->set('flash_error', $this->translator->get('PHP Zip extension is not loaded.'));
            return $response->withHeader('Location', admin_url('/themes/upload'))->withStatus(302);
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'vl_theme_');
        $zipFile->moveTo($tmpPath);

        $zip = new ZipArchive();
        if ($zip->open($tmpPath) !== true) {
            @unlink($tmpPath);
            $this->session->set('flash_error', $this->translator->get('Failed to open ZIP file.'));
            return $response->withHeader('Location', admin_url('/themes/upload'))->withStatus(302);
        }

        // Security: Zip Slip protection
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_contains($name, '..') || str_starts_with($name, '/') || str_starts_with($name, '\\')) {
                $zip->close();
                @unlink($tmpPath);
                $this->session->set('flash_error', $this->translator->get('Invalid ZIP: Path traversal detected.'));
                return $response->withHeader('Location', admin_url('/themes/upload'))->withStatus(302);
            }
        }

        // 1. Identify theme directory inside ZIP
        $themeDirName = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = $stat['name'];
            if (str_ends_with($name, 'theme.json')) {
                if ($name === 'theme.json') {
                    $themeDirName = '';
                    break;
                }
                $parts = explode('/', $name);
                if (count($parts) === 2 && $parts[1] === 'theme.json') {
                    $themeDirName = $parts[0];
                    break;
                }
            }
        }

        if ($themeDirName === null) {
            $zip->close();
            @unlink($tmpPath);
            $this->session->set('flash_error', $this->translator->get('Invalid theme: theme.json not found in the root or a single sub-folder.'));
            return $response->withHeader('Location', admin_url('/themes/upload'))->withStatus(302);
        }

        // Check for templates directory
        $hasTemplates = false;
        $prefix       = $themeDirName === '' ? '' : $themeDirName . '/';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (str_starts_with($stat['name'], $prefix . 'templates/')) {
                $hasTemplates = true;
                break;
            }
        }

        if (!$hasTemplates) {
            $zip->close();
            @unlink($tmpPath);
            $this->session->set('flash_error', $this->translator->get('Invalid theme: "templates/" directory not found.'));
            return $response->withHeader('Location', admin_url('/themes/upload'))->withStatus(302);
        }

        // 2. Validate slug
        $slug = $themeDirName ?: pathinfo($zipFile->getClientFilename() ?: 'theme', PATHINFO_FILENAME);
        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($slug));

        if (!$slug) {
            $zip->close();
            @unlink($tmpPath);
            $this->session->set('flash_error', $this->translator->get('Invalid theme name.'));
            return $response->withHeader('Location', admin_url('/themes/upload'))->withStatus(302);
        }

        $destination = base_path('themes/' . $slug);

        if (is_dir($destination) && !$overwrite) {
            $zip->close();
            @unlink($tmpPath);
            $this->session->set('flash_error', $this->translator->get('Theme ":slug" already exists.', [':slug' => $slug]));
            return $response->withHeader('Location', admin_url('/themes/upload'))->withStatus(302);
        }

        // 3. Extract
        $extractPath = sys_get_temp_dir() . '/vl_theme_extract_' . uniqid();
        mkdir($extractPath, 0755, true);
        $zip->extractTo($extractPath);
        $zip->close();
        @unlink($tmpPath);

        $sourcePath = ($themeDirName === '') ? $extractPath : $extractPath . '/' . $themeDirName;

        if (is_dir($destination)) {
            $this->rmdirRecursive($destination);
        }

        $this->moveTree($sourcePath, $destination);
        $this->rmdirRecursive($extractPath);

        $this->session->set('flash_success', $this->translator->get('Theme ":slug" uploaded successfully.', [':slug' => $slug]));
        return $response->withHeader('Location', admin_url('/themes'))->withStatus(302);
    }

    /**
     * POST /admin/themes/delete — delete a theme.
     */
    public function destroy(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $slug = trim((string) ($body['theme'] ?? ''));

        if (!$this->isValidThemeSlug($slug)) {
            $this->session->set('flash_error', 'No theme specified.');
            return $response->withHeader('Location', admin_url('/themes'))->withStatus(302);
        }

        if ($slug === $this->themeManager->getActiveTheme()) {
            $this->session->set('flash_error', 'Cannot delete the active theme.');
            return $response->withHeader('Location', admin_url('/themes'))->withStatus(302);
        }

        // Security check: only allow deleting from the themes/ directory
        $themePath = base_path('themes/' . $slug);
        if (!is_dir($themePath)) {
            $this->session->set('flash_error', 'Theme not found.');
            return $response->withHeader('Location', admin_url('/themes'))->withStatus(302);
        }

        $this->rmdirRecursive($themePath);
        $this->settings->delete('theme_' . $slug . '_colors');

        $this->session->set('flash_success', $this->translator->get('Theme ":name" deleted.', [':name' => $slug]));
        return $response->withHeader('Location', admin_url('/themes'))->withStatus(302);
    }

    /**
     * GET /admin/themes/{slug}/colors — show color customizer for a theme.
     */
    public function colors(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $slug      = $args['slug'] ?? '';
        if (!$this->isValidThemeSlug($slug)) {
            $this->session->set('flash_error', 'Theme not found.');
            return $response->withHeader('Location', admin_url('/themes'))->withStatus(302);
        }
        $themePath = base_path('themes/' . $slug);

        if (!is_dir($themePath)) {
            $this->session->set('flash_error', $this->translator->get('Theme not found.'));
            return $response->withHeader('Location', admin_url('/themes'))->withStatus(302);
        }

        $schema       = $this->themeManager->getColorVariablesSchema($slug);
        $savedRaw     = $this->settings->get('theme_' . $slug . '_colors', '');
        $savedColors  = json_decode($savedRaw, true);
        $hasCustom    = is_array($savedColors) && !empty($savedColors);
        $savedColors  = is_array($savedColors) ? $savedColors : [];

        $currentColors = [];
        foreach ($schema as $varName => $spec) {
            $currentColors[$varName] = $savedColors[$varName] ?? ($spec['default'] ?? '#000000');
        }

        $themes    = $this->themeManager->discoverThemes();
        $themeMeta = $themes[$slug] ?? ['name' => $slug, 'slug' => $slug];

        return $this->twig->render($response, 'admin/themes/colors.twig', [
            'theme_slug'     => $slug,
            'theme_meta'     => $themeMeta,
            'schema'         => $schema,
            'current_colors' => $currentColors,
            'has_custom'     => $hasCustom,
        ]);
    }

    /**
     * POST /admin/themes/{slug}/colors — save custom theme colors.
     */
    public function saveColors(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $slug      = $args['slug'] ?? '';
        if (!$this->isValidThemeSlug($slug)) {
            $this->session->set('flash_error', 'Theme not found.');
            return $response->withHeader('Location', admin_url('/themes'))->withStatus(302);
        }
        $themePath = base_path('themes/' . $slug);

        if (!is_dir($themePath)) {
            $this->session->set('flash_error', $this->translator->get('Theme not found.'));
            return $response->withHeader('Location', admin_url('/themes'))->withStatus(302);
        }

        $body   = (array) $request->getParsedBody();
        $schema = $this->themeManager->getColorVariablesSchema($slug);

        $newColors = [];
        foreach ($schema as $varName => $spec) {
            $val = trim((string) ($body['colors'][$varName] ?? ''));
            if ($val !== '' && ColorValueValidator::isValid($val)) {
                $newColors[$varName] = $val;
            } else {
                $newColors[$varName] = $spec['default'] ?? '#000000';
            }
        }

        $this->settings->set('theme_' . $slug . '_colors', json_encode($newColors), 'theme');
        $this->pageCache?->flush();

        $this->session->set('flash_success', $this->translator->get('Theme colors saved successfully.'));
        return $response->withHeader('Location', admin_url('/themes/' . $slug . '/colors'))->withStatus(302);
    }

    /**
     * POST /admin/themes/{slug}/colors/reset — reset theme colors to default.
     */
    public function resetColors(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $slug      = $args['slug'] ?? '';
        if (!$this->isValidThemeSlug($slug)) {
            $this->session->set('flash_error', 'Theme not found.');
            return $response->withHeader('Location', admin_url('/themes'))->withStatus(302);
        }
        $themePath = base_path('themes/' . $slug);

        if (!is_dir($themePath)) {
            $this->session->set('flash_error', $this->translator->get('Theme not found.'));
            return $response->withHeader('Location', admin_url('/themes'))->withStatus(302);
        }

        $this->settings->delete('theme_' . $slug . '_colors');
        $this->pageCache?->flush();

        $this->session->set('flash_success', $this->translator->get('Theme colors reset to defaults.'));
        return $response->withHeader('Location', admin_url('/themes/' . $slug . '/colors'))->withStatus(302);
    }

    /**
     * Recursively move a directory tree using copy + unlink for cross-partition compatibility.
     */
    private function moveTree(string $src, string $dst): void
    {
        if (!is_dir($dst)) {
            mkdir($dst, 0755, true);
        }

        $files = scandir($src);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $sourcePath = $src . '/' . $file;
            $destPath   = $dst . '/' . $file;

            if (is_dir($sourcePath)) {
                $this->moveTree($sourcePath, $destPath);
            } else {
                copy($sourcePath, $destPath);
                unlink($sourcePath);
            }
        }
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->rmdirRecursive("$dir/$file") : unlink("$dir/$file");
        }
        rmdir($dir);
    }
}
