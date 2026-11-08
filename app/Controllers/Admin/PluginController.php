<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Views\Twig;
use ValeApp\Application\Plugin\InvalidPluginException;
use ValeApp\Application\Plugin\MuPluginMetadata;
use ValeApp\Application\Plugin\PluginCapabilitiesChangedException;
use ValeApp\Application\Plugin\PluginManager;
use ValeApp\Application\Plugin\PluginManifest;
use ValeApp\Application\Plugin\PluginMigrator;
use ValeApp\Application\Plugin\PluginSecurityScanner;
use ValeApp\Application\Plugin\PluginUpdateService;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Infrastructure\Cache\PageCache;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

final class PluginController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly PluginManager $plugins,
        private readonly PluginMigrator $migrator,
        private readonly PluginUpdateService $updater,
        private readonly PageCache $pageCache,
        private readonly SessionManager $session,
        private readonly Translator $translator,
        private readonly SettingsRepositoryInterface $settings,
        private readonly PluginSecurityScanner $scanner,
    ) {
    }

    /**
     * GET /admin/plugins — installed plugins, activation state, last failure.
     */
    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $search = trim((string) ($params['search'] ?? ''));
        $status = (string) ($params['status'] ?? '');
        if (!in_array($status, ['', 'enabled', 'disabled', 'must_use'], true)) {
            $status = '';
        }

        $rows       = array_merge($this->plugins->findAll(), $this->muPluginRows());
        $allPlugins = count($rows);

        if ($status !== '') {
            $rows = array_values(array_filter(
                $rows,
                static function (array $row) use ($status): bool {
                    if ($status === 'must_use') {
                        return !empty($row['must_use']);
                    }
                    if (!empty($row['must_use']) || !empty($row['error'])) {
                        return false;
                    }

                    return $status === 'enabled' ? !empty($row['enabled']) : empty($row['enabled']);
                },
            ));
        }

        $totalPlugins = count($rows);
        $clientSide   = $totalPlugins <= client_side_search_fetch_limit();

        if ($clientSide) {
            $total   = $totalPlugins;
            $page    = 1;
            $perPage = 20;
        } else {
            if ($search !== '') {
                $needle = mb_strtolower($search);
                $rows   = array_values(array_filter(
                    $rows,
                    static fn (array $row): bool => str_contains(
                        mb_strtolower(implode(' ', array_filter([
                            $row['name'],
                            $row['slug'],
                            (string) ($row['description'] ?? ''),
                            (string) ($row['license'] ?? ''),
                            (string) ($row['homepage'] ?? ''),
                            (string) ($row['requires_vale'] ?? ''),
                            (string) ($row['requires_php'] ?? ''),
                        ]))),
                        $needle,
                    ),
                ));
            }

            $total   = count($rows);
            $page    = max(1, (int) ($params['page'] ?? 1));
            $perPage = max(1, min(100, (int) ($params['per_page'] ?? 20)));
            $rows    = array_slice($rows, ($page - 1) * $perPage, $perPage);
        }

        $failures  = $this->plugins->failures();
        $updates   = $this->updater->status();
        $hiddenNav = $this->plugins->getHiddenNavSlugs();

        foreach ($rows as $index => $row) {
            $rows[$index]['nav_hidden'] = in_array($row['slug'], $hiddenNav, true);

            if (!empty($row['must_use'])) {
                $rows[$index]['failure']    = null;
                $rows[$index]['migrations'] = ['pending' => null, 'pending_error' => null, 'last' => null];
                $rows[$index]['update']     = null;
                continue;
            }

            $rows[$index]['failure']    = $failures[$row['slug']] ?? null;
            $rows[$index]['migrations'] = $this->migrationState($row['slug']);
            $rows[$index]['update']     = $updates[$row['slug']] ?? null;
        }

        $baseUrl = admin_url('/plugins');
        if ($status !== '') {
            $baseUrl .= '?status=' . rawurlencode($status);
        }

        return $this->twig->render($response, 'admin/plugins/index.twig', [
            'plugins'           => $rows,
            'operator_disabled' => $this->plugins->isDisabledByOperator(),
            'plugins_enabled'   => $this->plugins->isGloballyEnabled(),
            'vale_version'      => $this->plugins->valeVersion(),
            'total_plugins'     => $allPlugins,
            'total'             => $total,
            'filters'           => ['search' => $search, 'status' => $status],
            'client_side'       => $clientSide,
            'page'              => $page,
            'per_page'          => $perPage,
            'base_url'          => $baseUrl,
        ]);
    }

    /**
     * POST /admin/plugins/toggle — enable or disable one plugin.
     */
    public function toggle(Request $request, Response $response): Response
    {
        $body   = (array) $request->getParsedBody();
        $slug   = trim((string) ($body['slug'] ?? ''));
        $action = (string) ($body['action'] ?? '');

        if (!PluginManifest::isValidSlug($slug) || !in_array($action, ['enable', 'disable'], true)) {
            $this->session->set('flash_error', $this->translator->get('Unknown plugin.'));

            return $response->withHeader('Location', admin_url('/plugins'))->withStatus(302);
        }

        try {
            if ($action === 'enable') {
                $this->plugins->enable($slug);

                $migration = $this->runMigrations($slug);
                $message   = $this->translator->get('Plugin ":name" enabled.', [':name' => $slug]);
                if ($migration['applied'] > 0) {
                    $message .= ' ' . $this->translator->get(
                        '%d migration(s) applied.',
                        ['%d' => $migration['applied']],
                    );
                }
                $this->session->set('flash_success', $message);

                if ($migration['error'] !== null) {
                    $this->session->set('flash_error', $migration['error']);
                }
            } else {
                $this->plugins->disable($slug);
                $this->session->set('flash_success', $this->translator->get(
                    'Plugin ":name" disabled.',
                    [':name' => $slug],
                ));
            }

            // Guest pages rendered by the plugin must not survive the switch.
            $this->pageCache->flush();
        } catch (InvalidPluginException $e) {
            error_log('[Vale plugin] ' . $e->getMessage());
            $this->session->set('flash_error', $e->userMessage ?? $e->getMessage());
        }

        return $response->withHeader('Location', admin_url('/plugins'))->withStatus(302);
    }

    /**
     * POST /admin/plugins/scan — capabilities an enable action would grant.
     *
     * Accepts one slug or a comma/JSON list so the bulk enable dialog can check
     * the whole selection in a single request.
     */
    public function scan(Request $request, Response $response): Response
    {
        $body  = (array) $request->getParsedBody();
        $slugs = $this->parseSlugs($body['slugs'] ?? $body['slug'] ?? '');

        if ($slugs === []) {
            return $this->json($response, [
                'error'   => $this->translator->get('Unknown plugin.'),
                'plugins' => [],
            ], 422);
        }

        $plugins = [];
        $error   = null;

        foreach ($slugs as $slug) {
            $scan        = $this->scanOne($slug);
            $plugins[]   = $scan;
            $error     ??= $scan['error'];
        }

        return $this->json($response, [
            'plugins'      => $plugins,
            'slug'         => $plugins[0]['slug'],
            'name'         => $plugins[0]['name'],
            'capabilities' => $plugins[0]['capabilities'],
            'violations'   => $plugins[0]['violations'],
            'error'        => $error,
        ]);
    }

    /**
     * @return array{slug:string, name:string, capabilities:list<string>, violations:list<string>, error:?string}
     */
    private function scanOne(string $slug): array
    {
        try {
            $manifest  = $this->plugins->find($slug);
            $directory = $this->plugins->resolveDirectory($slug);
        } catch (\Throwable $e) {
            error_log('[Vale plugin] scan failed: ' . $e->getMessage());

            return [
                'slug'         => $slug,
                'name'         => $slug,
                'capabilities' => [],
                'violations'   => [],
                'error'        => $this->translator->get('This plugin could not be checked.'),
            ];
        }

        if ($manifest === null || $directory === null) {
            return [
                'slug'         => $slug,
                'name'         => $slug,
                'capabilities' => [],
                'violations'   => [],
                'error'        => $this->translator->get('Unknown plugin.'),
            ];
        }

        $scan         = $this->scanner->scanDirectory($directory, $manifest->capabilities);
        $capabilities = array_values(array_unique(array_merge(
            $manifest->capabilities,
            $scan['detected_capabilities'],
        )));
        sort($capabilities);

        $violations = array_values(array_unique(array_column($scan['violations'], 'capability')));
        sort($violations);

        return [
            'slug'         => $slug,
            'name'         => $manifest->name,
            'capabilities' => $capabilities,
            'violations'   => $violations,
            'error'        => $violations === [] ? null : $this->translator->get(
                'Plugin uses undeclared capabilities: :list',
                [':list' => implode(', ', $violations)],
            ),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($data));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    /**
     * POST /admin/plugins/nav-visibility — hide or show a plugin's sidebar link.
     */
    public function setNavVisibility(Request $request, Response $response): Response
    {
        $body   = (array) $request->getParsedBody();
        $slug   = trim((string) ($body['slug'] ?? ''));
        $hidden = (string) ($body['hidden'] ?? '');

        if (!PluginManifest::isValidSlug($slug) || !in_array($hidden, ['0', '1'], true)) {
            $this->session->set('flash_error', $this->translator->get('Unknown plugin.'));

            return $response->withHeader('Location', admin_url('/plugins'))->withStatus(302);
        }

        try {
            $manifest = $this->plugins->find($slug);
        } catch (\Throwable $e) {
            $this->session->set('flash_error', $this->translator->get(
                'Could not update plugin navigation: :error',
                [':error' => $e->getMessage()],
            ));

            return $response->withHeader('Location', admin_url('/plugins'))->withStatus(302);
        }

        if ($manifest === null || $manifest->admin === null) {
            $this->session->set('flash_error', $this->translator->get('This plugin does not add a sidebar entry.'));

            return $response->withHeader('Location', admin_url('/plugins'))->withStatus(302);
        }

        try {
            $this->plugins->setNavHidden($slug, $hidden === '1');
            if ($hidden === '1') {
                $this->session->set('flash_success', $this->translator->get(
                    'Plugin ":name" hidden from the sidebar.',
                    [':name' => $manifest->name],
                ));
            } else {
                $this->session->set('flash_success', $this->translator->get(
                    'Plugin ":name" shown in the sidebar.',
                    [':name' => $manifest->name],
                ));
            }
        } catch (\Throwable $e) {
            $this->session->set('flash_error', $this->translator->get(
                'Could not update plugin navigation: :error',
                [':error' => $e->getMessage()],
            ));
        }

        return $response->withHeader('Location', admin_url('/plugins'))->withStatus(302);
    }

    /**
     * POST /admin/plugins/global — master switch for all plugin code.
     * Individual activation state is intentionally left untouched.
     */
    public function toggleGlobal(Request $request, Response $response): Response
    {
        $body    = (array) $request->getParsedBody();
        $enabled = (string) ($body['enabled'] ?? '');

        if (!in_array($enabled, ['0', '1'], true)) {
            $this->session->set('flash_error', $this->translator->get('Unknown plugin action.'));

            return $response->withHeader('Location', admin_url('/plugins'))->withStatus(302);
        }

        $turnOn = $enabled === '1';
        $this->plugins->setGloballyEnabled($turnOn);
        $this->pageCache->flush();

        $this->session->set(
            'flash_success',
            $turnOn
                ? $this->translator->get('Plugins enabled.')
                : $this->translator->get('Plugins disabled. Activation states are kept.'),
        );

        return $response->withHeader('Location', admin_url('/plugins'))->withStatus(302);
    }

    /**
     * POST /admin/plugins/install — install one uploaded plugin ZIP.
     * The plugin stays disabled until an administrator enables it.
     */
    public function install(Request $request, Response $response): Response
    {
        $files = $request->getUploadedFiles();
        $file  = $files['plugin_zip'] ?? null;

        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            $this->session->set('flash_error', $this->translator->get('Please upload a valid ZIP file.'));

            return $response->withHeader('Location', admin_url('/plugins'))->withStatus(302);
        }

        $filename = $file->getClientFilename() ?: 'plugin.zip';
        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'zip') {
            $this->session->set('flash_error', $this->translator->get('Please upload a plugin ZIP file.'));

            return $response->withHeader('Location', admin_url('/plugins'))->withStatus(302);
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'vl_plugin_');
        if ($tmpPath === false) {
            $this->session->set('flash_error', $this->translator->get('Could not prepare the uploaded plugin package.'));

            return $response->withHeader('Location', admin_url('/plugins'))->withStatus(302);
        }

        try {
            $file->moveTo($tmpPath);
            $result = $this->updater->installFromArchive($tmpPath, $filename);

            $message  = $this->translator->get(
                'Plugin ":name" v:version installed. It is disabled; review it and enable it when ready.',
                [':name' => $result['name'], ':version' => $result['version']],
            );
            $manifest = $this->plugins->find((string) ($result['slug'] ?? ''));
            if ($manifest !== null && $manifest->capabilities !== []) {
                $message .= ' ' . $this->translator->get(
                    'It requests: :caps.',
                    [':caps' => implode(', ', $manifest->capabilities)],
                );
            }
            $this->session->set('flash_success', $message);
        } catch (\Throwable $e) {
            error_log('[Vale plugin] install failed: ' . $e->getMessage());
            $message = $e instanceof InvalidPluginException && $e->userMessage !== null
                ? $e->userMessage
                : $e->getMessage();
            $this->session->set('flash_error', $this->translator->get(
                'Plugin installation failed: :error',
                [':error' => $message],
            ));
        } finally {
            if (is_file($tmpPath)) {
                @unlink($tmpPath);
            }
        }

        return $response->withHeader('Location', admin_url('/plugins'))->withStatus(302);
    }

    /**
     * POST /admin/plugins/check-updates — check every declared update feed.
     */
    public function checkUpdates(Request $request, Response $response): Response
    {
        $sources = array_filter(
            $this->plugins->findAll(),
            static fn (array $row): bool => ($row['updates'] ?? null) !== null,
        );

        if ($sources === []) {
            $this->session->set('flash_error', $this->translator->get(
                'No installed plugin declares an update source. Add an "updates" URL to the plugin manifest to enable update checks.',
            ));

            return $response->withHeader('Location', admin_url('/plugins'))->withStatus(302);
        }

        try {
            $results   = $this->updater->checkAll();
            $available = 0;
            $errors    = [];

            foreach ($results as $slug => $status) {
                if (!empty($status['error'])) {
                    $errors[] = $slug . ': ' . (string) $status['error'];
                } elseif (!empty($status['available'])) {
                    $available++;
                }
            }

            if ($available > 0) {
                $this->session->set('flash_success', $this->translator->get(
                    ':count plugin update(s) available.',
                    [':count' => (string) $available],
                ));
            } else {
                $this->session->set('flash_success', $this->translator->get(
                    'All plugins with update sources are up to date.',
                ));
            }

            if ($errors !== []) {
                $this->session->set('flash_error', $this->translator->get(
                    'Some plugin update checks failed: :errors',
                    [':errors' => implode('; ', $errors)],
                ));
            }
        } catch (\Throwable $e) {
            $this->session->set('flash_error', $this->translator->get(
                'Plugin update check failed: :error',
                [':error' => $e->getMessage()],
            ));
        }

        return $response->withHeader('Location', admin_url('/plugins'))->withStatus(302);
    }

    /**
     * POST /admin/plugins/update — install the cached/newest release of one plugin.
     */
    public function update(Request $request, Response $response): Response
    {
        $body                = (array) $request->getParsedBody();
        $slug                = trim((string) ($body['slug'] ?? ''));
        $acknowledgedVersion = trim((string) ($body['acknowledge_version'] ?? ''));

        if (!PluginManifest::isValidSlug($slug)) {
            $this->session->set('flash_error', $this->translator->get('Unknown plugin.'));

            return $response->withHeader('Location', admin_url('/plugins'))->withStatus(302);
        }

        try {
            $result  = $this->updater->update($slug, $acknowledgedVersion === '' ? null : $acknowledgedVersion);
            $message = $this->translator->get(
                'Plugin ":name" updated from v:from to v:to.',
                [
                    ':name' => $slug,
                    ':from' => $result['from'],
                    ':to'   => $result['to'],
                ],
            );

            if ($result['migrations'] > 0) {
                $message .= ' ' . $this->translator->get(
                    '%d migration(s) applied.',
                    ['%d' => (string) $result['migrations']],
                );
            }

            $this->pageCache->flush();
            $this->session->set('flash_success', $message);
        } catch (PluginCapabilitiesChangedException $e) {
            return $this->twig->render($response, 'admin/plugins/confirm-update.twig', [
                'plugin_slug'      => $e->slug,
                'new_version'      => $e->version,
                'new_capabilities' => $e->capabilities,
            ]);
        } catch (\Throwable $e) {
            error_log('[Vale plugin] update failed: ' . $e->getMessage());
            $message = $e instanceof InvalidPluginException && $e->userMessage !== null
                ? $e->userMessage
                : $e->getMessage();
            $this->session->set('flash_error', $this->translator->get(
                'Plugin update failed: :error',
                [':error' => $message],
            ));
        }

        return $response->withHeader('Location', admin_url('/plugins'))->withStatus(302);
    }

    /**
     * POST /admin/plugins/bulk — enable or disable every selected plugin.
     */
    public function bulkAction(Request $request, Response $response): Response
    {
        $body   = (array) $request->getParsedBody();
        $action = (string) ($body['action'] ?? '');
        $slugs  = $this->parseSlugs($body['ids'] ?? $body['slugs'] ?? '');

        if ($slugs === []) {
            $this->session->set('flash_error', $this->translator->get('No items selected.'));

            return $response->withHeader('Location', admin_url('/plugins'))->withStatus(302);
        }

        if (!in_array($action, ['enable', 'disable'], true)) {
            $this->session->set('flash_error', $this->translator->get('Unknown plugin action.'));

            return $response->withHeader('Location', admin_url('/plugins'))->withStatus(302);
        }

        $changed = 0;
        $errors  = [];

        if ($action === 'enable') {
            [$changed, $errors] = $this->enableMany($slugs);
        } else {
            foreach ($slugs as $slug) {
                if (!$this->plugins->isEnabled($slug)) {
                    continue;
                }

                $this->plugins->disable($slug);
                $changed++;
            }
        }

        if ($changed > 0) {
            $this->pageCache->flush();
            $message = $action === 'enable'
                ? $this->translator->get(':count plugin(s) enabled.', [':count' => (string) $changed])
                : $this->translator->get(':count plugin(s) disabled.', [':count' => (string) $changed]);
            $this->session->set('flash_success', $message);
        } elseif ($errors === []) {
            $this->session->set('flash_success', $this->translator->get('No changes.'));
        }

        if ($errors !== []) {
            $lines = [];
            foreach ($errors as $slug => $message) {
                $lines[] = $slug . ': ' . $message;
            }

            $this->session->set('flash_error', $this->translator->get(
                'Some plugins could not be updated: :errors',
                [':errors' => implode('; ', $lines)],
            ));
        }

        return $response->withHeader('Location', admin_url('/plugins'))->withStatus(302);
    }

    /**
     * POST /admin/plugins/delete — remove an installed plugin.
     *
     * Without `delete_data` only the plugin directory is deleted; its settings,
     * failure log, update cache and migration bookkeeping are kept for a later
     * reinstall. With `delete_data` every applied migration is rolled back first,
     * then the files, `plugin:<slug>:*` settings and bookkeeping are removed.
     */
    public function delete(Request $request, Response $response): Response
    {
        $body       = (array) $request->getParsedBody();
        $slug       = trim((string) ($body['slug'] ?? ''));
        $deleteData = (string) ($body['delete_data'] ?? '') === '1';

        if (!PluginManifest::isValidSlug($slug) || $this->plugins->resolveDirectory($slug) === null) {
            $this->session->set('flash_error', $this->translator->get('Unknown plugin.'));

            return $response->withHeader('Location', admin_url('/plugins'))->withStatus(302);
        }

        try {
            $reverted = 0;

            if ($deleteData) {
                $reverted = count($this->migrator->rollback($slug, false, true)['reverted']);
            }

            $this->plugins->disable($slug);
            $this->plugins->deleteFiles($slug);

            if ($deleteData) {
                $deleted = $this->settings->deleteByPrefix('plugin:' . $slug . ':');
                $this->plugins->clearFailure($slug);
                $this->migrator->forget($slug);
                $this->updater->forget($slug);

                $message = $this->translator->get(
                    'Plugin ":name" deleted with its settings and data (:settings settings, :migrations migration(s) rolled back).',
                    [
                        ':name'       => $slug,
                        ':settings'   => (string) $deleted,
                        ':migrations' => (string) $reverted,
                    ],
                );
            } else {
                $message = $this->translator->get(
                    'Plugin ":name" deleted. Its settings and data were kept.',
                    [':name' => $slug],
                );
            }

            $this->pageCache->flush();
            $this->session->set('flash_success', $message);
        } catch (\Throwable $e) {
            $this->session->set('flash_error', $this->translator->get(
                'Plugin deletion failed: :error',
                [':error' => $e->getMessage()],
            ));
        }

        return $response->withHeader('Location', admin_url('/plugins'))->withStatus(302);
    }

    /**
     * Enable plugins in repeated passes so a dependency selected in the same
     * batch can be enabled first regardless of the posted order.
     *
     * @param list<string> $slugs
     * @return array{0:int,1:array<string,string>}
     */
    private function enableMany(array $slugs): array
    {
        $pending       = $slugs;
        $changed       = 0;
        $errors        = [];
        $lastException = [];

        while ($pending !== []) {
            $progress = false;

            foreach ($pending as $index => $slug) {
                if ($this->plugins->isEnabled($slug)) {
                    unset($pending[$index], $lastException[$slug]);
                    $progress = true;
                    continue;
                }

                try {
                    $this->plugins->enable($slug);

                    $migration = $this->runMigrations($slug);
                    if ($migration['error'] !== null) {
                        $errors[$slug] = $migration['error'];
                    }

                    $changed++;
                    unset($pending[$index], $lastException[$slug]);
                    $progress = true;
                } catch (InvalidPluginException $e) {
                    error_log('[Vale plugin] ' . $e->getMessage());
                    $userMessage          = $e->userMessage ?? $e->getMessage();
                    $lastException[$slug] = $userMessage;

                    if (!$this->dependsOnPending($slug, $pending)) {
                        $errors[$slug] = $userMessage;
                        unset($pending[$index]);
                        $progress = true;
                    }
                }
            }

            if (!$progress) {
                foreach ($pending as $slug) {
                    $errors[$slug] = $lastException[$slug] ?? $this->translator->get('Dependencies are not enabled.');
                }
                break;
            }

            $pending = array_values($pending);
        }

        return [$changed, $errors];
    }

    /**
     * @param array<int|string,string> $pending
     */
    private function dependsOnPending(string $slug, array $pending): bool
    {
        try {
            $manifest = $this->plugins->find($slug);
        } catch (\Throwable) {
            return false;
        }

        if ($manifest === null) {
            return false;
        }

        foreach (array_keys($manifest->requiresPlugin) as $dependency) {
            if (in_array($dependency, $pending, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function parseSlugs(mixed $raw): array
    {
        if (is_string($raw)) {
            $trimmed = trim($raw);
            if (str_starts_with($trimmed, '[')) {
                $decoded = json_decode($trimmed, true);
                $raw     = is_array($decoded) ? $decoded : [];
            } else {
                $raw = $trimmed === '' ? [] : explode(',', $trimmed);
            }
        }

        if (!is_array($raw)) {
            return [];
        }

        $slugs = [];
        foreach ($raw as $slug) {
            if (!is_string($slug)) {
                continue;
            }

            $slug = trim($slug);
            if (PluginManifest::isValidSlug($slug) && !in_array($slug, $slugs, true)) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    /**
     * Activation runs the plugin's pending migrations. A failure is reported
     * but never undoes the activation — the plugin is already enabled and only
     * the schema part needs attention.
     *
     * @return array{applied:int, error:?string}
     */
    private function runMigrations(string $slug): array
    {
        try {
            return ['applied' => count($this->migrator->migrate($slug)['applied']), 'error' => null];
        } catch (\Throwable $e) {
            return ['applied' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Pending migration count plus the outcome of the last run, for the list.
     * A broken migrations directory must not take the page down: the count is
     * dropped and the reason is shown in the row instead.
     *
     * @return array{pending:?int, pending_error:?string, last:?array<string, mixed>}
     */
    private function migrationState(string $slug): array
    {
        try {
            $pending = count($this->migrator->pending($slug));
            $error   = null;
        } catch (\Throwable $e) {
            $pending = null;
            $error   = $e->getMessage();
        }

        return [
            'pending'       => $pending,
            'pending_error' => $error,
            'last'          => $this->migrator->lastResult($slug),
        ];
    }

    /**
     * Must-use drop-ins are always active and file-managed. They are rendered as
     * rows in the same table so search and pagination cover both plugin kinds.
     *
     * @return list<array<string, mixed>>
     */
    private function muPluginRows(): array
    {
        $files = glob(dirname($this->plugins->pluginsDir()) . '/mu-plugins/*.php') ?: [];
        $rows  = [];

        foreach ($files as $file) {
            $name        = basename($file, '.php');
            $metadata    = MuPluginMetadata::fromFile($file, $name);
            $installedAt = @filectime($file);
            $updatedAt   = @filemtime($file);

            $rows[] = [
                'slug'               => $name,
                'name'               => $metadata->name,
                'version'            => $metadata->version ?? '—',
                'description'        => $metadata->description,
                'requires_vale'      => null,
                'requires_php'       => null,
                'requires_ext'       => [],
                'license'            => $metadata->license,
                'homepage'           => $metadata->homepage,
                'settings'           => null,
                'updates'            => null,
                'requires_plugin'    => [],
                'dependency_problem' => null,
                'enabled'            => false,
                'error'              => null,
                'must_use'           => true,
                'installed_at'       => $installedAt === false ? null : date('Y-m-d H:i:s', $installedAt),
                'updated_at'         => $updatedAt   === false ? null : date('Y-m-d H:i:s', $updatedAt),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['slug'], $b['slug']));

        return $rows;
    }
}
