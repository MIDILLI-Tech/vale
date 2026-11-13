<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use ValeApp\Domain\Content\Entity\ContentType;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Content\RoutingValidator;
use ValeApp\Domain\Roles\Repository\RoleRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Infrastructure\Cache\PageCache;
use ValeApp\Infrastructure\Content\RedirectService;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

final class ContentTypeController
{
    public function __construct(
        private readonly Translator $translator,
        private readonly Twig $twig,
        private readonly ContentTypeRepositoryInterface $contentTypes,
        private readonly SessionManager $session,
        private readonly RoleRepositoryInterface $roles,
        private readonly RedirectService $redirects,
        private readonly DbalSettingsRepository $settings,
        private readonly PostRepositoryInterface $posts,
        private readonly RoutingValidator $routing,
        private readonly ?PageCache $pageCache = null,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'admin/content-types/index.twig', [
            'contentTypes' => $this->contentTypes->findAll(),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'admin/content-types/form.twig', [
            'type'     => null,
            'is_edit'  => false,
            'settings' => $this->formSettings(),
        ]);
    }

    /** @return array<string, mixed> */
    private function formSettings(): array
    {
        $defaultLocale = strtolower(trim((string) ($this->settings->get('default_locale', 'en') ?? 'en')));

        return [
            'auto_redirect_enabled'  => $this->settings->get('auto_redirect_enabled', '1'),
            'routing_blog_prefix'    => $this->settings->get('routing_blog_prefix', '/blog'),
            'routing_page_prefix'    => $this->settings->get('routing_page_prefix', ''),
            'multi_language_content' => $this->settings->get('multi_language_content', '0') === '1',
            'seo_enabled'            => $this->settings->get('seo_enabled', '1')            === '1',
            'default_locale'         => $defaultLocale,
            'content_languages'      => $this->parseContentLanguages($defaultLocale),
        ];
    }

    /** @return string[] */
    private function parseContentLanguages(string $defaultLocale): array
    {
        $raw   = (string) ($this->settings->get('content_languages', $defaultLocale) ?? $defaultLocale);
        $codes = array_values(array_filter(array_map(
            static fn (string $code): string => strtolower(trim($code)),
            explode(',', $raw),
        )));

        return $codes !== [] ? $codes : [$defaultLocale];
    }

    /** @param string[] $supports */
    private function normalizeSupports(array $supports): array
    {
        $supports = array_values(array_unique(array_filter($supports, static fn (mixed $s): bool => is_string($s) && $s !== '')));

        if ($this->settings->get('multi_language_content', '0') !== '1') {
            $supports = array_values(array_filter($supports, static fn (string $s): bool => $s !== 'i18n'));
        }

        if ($this->settings->get('seo_enabled', '1') !== '1') {
            $supports = array_values(array_filter($supports, static fn (string $s): bool => $s !== 'seo'));
        }

        return $supports;
    }

    public function store(Request $request, Response $response): Response
    {
        $body    = (array) $request->getParsedBody();
        $name    = trim($body['name'] ?? '');
        $slugStr = trim($body['slug'] ?? '');
        $urlPath = trim($body['route_prefix'] ?? '');

        if ($name === '') {
            $this->session->set('flash_error', $this->translator->get('Content type name cannot be empty.'));
            return $this->twig->render($response->withStatus(422), 'admin/content-types/form.twig', [
                'type'     => null,
                'is_edit'  => false,
                'old'      => $body,
                'settings' => $this->formSettings(),
            ]);
        }

        try {
            $slugVo  = $slugStr !== '' ? new Slug($slugStr) : new Slug($name);
            $slugStr = (string) $slugVo;
        } catch (\InvalidArgumentException $e) {
            $this->session->set('flash_error', $this->translator->get('Invalid slug.'));
            return $this->twig->render($response->withStatus(422), 'admin/content-types/form.twig', [
                'type'     => null,
                'is_edit'  => false,
                'old'      => $body,
                'settings' => $this->formSettings(),
            ]);
        }

        if ($this->contentTypes->slugExists($slugStr)) {
            $this->session->set('flash_error', $this->translator->get('Slug already exists.'));
            return $this->twig->render($response->withStatus(422), 'admin/content-types/form.twig', [
                'type'     => null,
                'is_edit'  => false,
                'old'      => $body,
                'settings' => $this->formSettings(),
            ]);
        }

        $basePath = ContentType::buildPublicBasePath($urlPath !== '' ? $urlPath : null, $slugStr);
        if ($conflict = $this->routing->getConflictError($basePath)) {
            $this->session->set('flash_error', $conflict);
            return $this->twig->render($response->withStatus(422), 'admin/content-types/form.twig', [
                'type'     => null,
                'is_edit'  => false,
                'old'      => $body,
                'settings' => $this->formSettings(),
            ]);
        }

        $fieldSchema = json_decode($body['field_schema_json'] ?? '[]', true) ?: [];
        foreach ($fieldSchema as $f) {
            $fName = trim((string) ($f['name'] ?? ''));
            if ($fName === '') {
                $msg = $this->translator->get('Field key (Name) is required for custom fields.');
                $this->session->set('flash_error', $msg);
                return $this->twig->render($response->withStatus(422), 'admin/content-types/form.twig', [
                    'type'     => null,
                    'is_edit'  => false,
                    'old'      => $body,
                    'settings' => $this->formSettings(),
                ]);
            }
        }

        $type = new ContentType($name, $slugVo);
        $type->setDescription($body['description'] ?? null);
        $type->setIcon($body['icon'] ?? 'document-text');
        $type->setSupports($this->normalizeSupports((array) ($body['supports'] ?? [])));
        $type->setFieldSchema($fieldSchema);

        if ($urlPath !== '') {
            $type->setRoutePrefix($urlPath);
        }

        $this->contentTypes->save($type);
        $this->roles->ensureContentTypePermissions($type->getSlug()->__toString(), $name);
        $this->pageCache?->flush();

        $this->session->set('flash_success', $this->translator->get('Content type created.'));
        return $response->withHeader('Location', admin_url('/content-types'))->withStatus(302);
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $type = $this->contentTypes->findById((int) $args['id']);
        if ($type === null) {
            return $response->withStatus(404);
        }

        return $this->twig->render($response, 'admin/content-types/form.twig', [
            'type'     => $type,
            'is_edit'  => true,
            'settings' => $this->formSettings(),
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $type = $this->contentTypes->findById((int) $args['id']);
        if ($type === null) {
            return $response->withStatus(404);
        }

        $body       = (array) $request->getParsedBody();
        $newSlugStr = trim($body['slug'] ?? '');
        $urlPath    = trim($body['route_prefix'] ?? '');
        $oldSlugStr = (string) $type->getSlug();
        $oldUrlPath = $type->getRoutePrefix();

        // Validate slug if changed
        if ($newSlugStr !== '' && $newSlugStr !== $oldSlugStr) {
            if ($this->contentTypes->slugExists($newSlugStr, $type->getId())) {
                $this->session->set('flash_error', $this->translator->get('Slug already exists.'));
                return $this->twig->render($response->withStatus(422), 'admin/content-types/form.twig', [
                    'type'     => $type,
                    'is_edit'  => true,
                    'old'      => $body,
                    'settings' => $this->formSettings(),
                ]);
            }
        }

        // Handle redirects when URL path changes
        $oldPrefix      = ContentType::buildPublicBasePath($oldUrlPath !== '' ? $oldUrlPath : null, $oldSlugStr);
        $newPrefix      = ContentType::buildPublicBasePath(
            $urlPath !== '' ? $urlPath : null,
            $type->isSystem() ? $oldSlugStr : ($newSlugStr ?: $oldSlugStr),
        );

        if ($newPrefix !== $oldPrefix && ($conflict = $this->routing->getConflictError($newPrefix, null, $type->getId()))) {
            $this->session->set('flash_error', $conflict);
            return $this->twig->render($response->withStatus(422), 'admin/content-types/form.twig', [
                'type'     => $type,
                'is_edit'  => true,
                'old'      => $body,
                'settings' => $this->formSettings(),
            ]);
        }

        $shouldRedirect = (!empty($body['create_redirects']) || ($this->settings->get('auto_redirect_enabled', '1') === '1')) && $newPrefix !== $oldPrefix;
        if ($shouldRedirect) {
            $this->redirects->recordPrefixRedirect($oldPrefix, $newPrefix);
        }

        $type->setName(trim($body['name'] ?? ''));
        $type->setDescription($body['description'] ?? null);
        $type->setIcon($body['icon'] ?? 'document-text');

        if (!$type->isSystem()) {
            $fieldSchema = json_decode($body['field_schema_json'] ?? '[]', true) ?: [];
            foreach ($fieldSchema as $f) {
                $fName = trim((string) ($f['name'] ?? ''));
                if ($fName === '') {
                    $msg = $this->translator->get('Field key (Name) is required for custom fields.');
                    $this->session->set('flash_error', $msg);
                    return $this->twig->render($response->withStatus(422), 'admin/content-types/form.twig', [
                        'type'     => $type,
                        'is_edit'  => true,
                        'old'      => $body,
                        'settings' => $this->formSettings(),
                    ]);
                }
            }

            $type->setSupports($this->normalizeSupports((array) ($body['supports'] ?? [])));
            $type->setFieldSchema($fieldSchema);
        }

        if (!$type->isSystem() && $newSlugStr !== '' && $newSlugStr !== $oldSlugStr) {
            $type->setSlug(new Slug($newSlugStr));
            // Update all posts with old content_type to new slug
            $this->posts->updateContentType($oldSlugStr, $newSlugStr);
            // Update permissions (e.g. cpt.evimevim.* -> cpt.kamil.*)
            $this->roles->renameContentTypePermissions($oldSlugStr, $newSlugStr);
        }

        // Handle URL path (route_prefix)
        if ($urlPath !== '') {
            $type->setRoutePrefix($urlPath);
        } else {
            $type->setRoutePrefix(null);
        }

        $this->contentTypes->save($type);
        $this->pageCache?->flush();

        $this->session->set('flash_success', $this->translator->get('Content type updated.'));
        return $response->withHeader('Location', admin_url('/content-types'))->withStatus(302);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $type = $this->contentTypes->findById((int) $args['id']);
        if ($type === null || $type->isSystem()) {
            return $response->withStatus(404);
        }

        $this->roles->removeContentTypePermissions($type->getSlug()->__toString());
        $this->contentTypes->delete($type->getId());
        $this->pageCache?->flush();
        $this->session->set('flash_success', $this->translator->get('Content type deleted.'));
        return $response->withHeader('Location', admin_url('/content-types'))->withStatus(302);
    }

    public function toggleActive(Request $request, Response $response, array $args): Response
    {
        $type = $this->contentTypes->findById((int) $args['id']);
        if ($type === null) {
            return $response->withStatus(404);
        }

        $type->setIsActive(!$type->isActive());
        $this->contentTypes->save($type);
        $this->pageCache?->flush();

        $msg = $type->isActive()
            ? $this->translator->get('Content type enabled.')
            : $this->translator->get('Content type disabled.');
        $this->session->set('flash_success', $msg);

        return $response->withHeader('Location', admin_url('/content-types'))->withStatus(302);
    }

    public function clone(Request $request, Response $response, array $args): Response
    {
        $original = $this->contentTypes->findById((int) $args['id']);
        if ($original === null) {
            return $response->withStatus(404);
        }

        $originalName = $original->getName();
        $newName      = $originalName . ' (Copy)';
        $originalSlug = (string) $original->getSlug();
        $newSlugStr   = $originalSlug . '-copy';

        // Ensure unique slug that does not collide with pages or other routes
        $counter = 1;
        while (
            $this->contentTypes->slugExists($newSlugStr)
            || $this->routing->getConflictError(
                ContentType::buildPublicBasePath($original->getRoutePrefix(), $newSlugStr),
            ) !== null
        ) {
            if ($counter > 100) {
                $this->session->set('flash_error', $this->translator->get('Could not clone: no available URL. Rename the original first.'));
                return $response->withHeader('Location', admin_url('/content-types'))->withStatus(302);
            }
            $newSlugStr = $originalSlug . '-copy-' . $counter;
            $counter++;
        }

        $cloned = new ContentType($newName, new Slug($newSlugStr));
        $cloned->setDescription($original->getDescription());
        $cloned->setIcon($original->getIcon());
        $cloned->setSupports($original->getSupports());
        $cloned->setFieldSchema($original->getFieldSchema());
        if ($original->getRoutePrefix() !== null) {
            $cloned->setRoutePrefix($original->getRoutePrefix());
        }

        $this->contentTypes->save($cloned);
        $this->roles->ensureContentTypePermissions($cloned->getSlug()->__toString(), $cloned->getName());

        $this->session->set('flash_success', $this->translator->get('Content type cloned.'));
        return $response->withHeader('Location', admin_url('/content-types/' . $cloned->getId() . '/edit'))->withStatus(302);
    }
}
