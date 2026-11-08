<?php

declare(strict_types=1);

namespace ValeApp\Application\Plugin;

use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Views\Twig;
use Twig\Loader\FilesystemLoader;
use ValeApp\Domain\Shared\Event\EventDispatcherInterface;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;

/**
 * Boots user-owned plugin code.
 *
 * Must run AFTER the DI container and Slim app exist but BEFORE routes are
 * registered: plugin routes then sit ahead of the `/{path:.+}` catch-all.
 *
 * Two sources are loaded:
 *   content/mu-plugins/*.php  — always active, a single file returning a
 *                               callable(App, ContainerInterface)
 *   content/plugins/<slug>/   — activated via the `enabled_plugins` setting
 *
 * Every failure is isolated: one broken plugin never prevents the others, or
 * the site itself, from loading.
 *
 * A manifest may declare an `admin` block; the loader turns those into sidebar
 * entries through the same `admin_nav_groups` filter plugins use directly, so a
 * declarative link appears/disappears with activation and respects permissions.
 */
final class PluginLoader
{
    public function __construct(
        private readonly PluginManager $plugins,
        private readonly string $muPluginsDir,
        private readonly App $app,
        private readonly ContainerInterface $container,
        private readonly ?Twig $twig = null,
    ) {
    }

    /**
     * @return array{loaded: list<string>, failed: array<string,string>, skipped: bool}
     */
    public function load(): array
    {
        $report = ['loaded' => [], 'failed' => [], 'skipped' => false];

        if ($this->plugins->isDisabledByOperator() || !$this->plugins->isGloballyEnabled()) {
            $report['skipped'] = true;

            return $report;
        }

        $this->loadMuPlugins($report);

        /** @var list<PluginManifest> $manifests */
        $manifests = [];
        foreach ($this->plugins->getEnabledSlugs() as $slug) {
            try {
                $directory = $this->plugins->resolveDirectory($slug);
                if ($directory === null) {
                    throw new InvalidPluginException('Plugin directory is missing.');
                }

                $manifest = PluginManifest::fromFile($directory . '/plugin.php', $slug);
                $reason   = $manifest->incompatibilityReason($this->plugins->valeVersion());
                if ($reason !== null) {
                    throw new InvalidPluginException($reason);
                }

                $manifests[] = $manifest;
            } catch (\Throwable $e) {
                $this->fail($report, $slug, $e);
            }
        }

        usort(
            $manifests,
            static fn (PluginManifest $a, PluginManifest $b): int => [$a->priority, $a->slug] <=> [$b->priority, $b->slug],
        );

        $manifestsBySlug = [];
        foreach ($manifests as $manifest) {
            $manifestsBySlug[$manifest->slug] = $manifest;
        }

        $loadable = [];
        foreach ($manifests as $manifest) {
            $problem = self::dependencyProblem($manifest, $manifestsBySlug);
            if ($problem !== null) {
                $this->fail($report, $manifest->slug, new InvalidPluginException($problem));
                continue;
            }

            $loadable[] = $manifest;
        }

        $manifests = $loadable;

        $adminEntries = [];
        foreach ($manifests as $manifest) {
            try {
                $this->registerAutoloader($manifest);
                $this->registerTemplateNamespace($manifest);
                ($manifest->register)($this->app, $this->container);

                $this->plugins->clearFailure($manifest->slug);
                $report['loaded'][] = $manifest->slug;

                if ($manifest->admin !== null) {
                    $adminEntries[] = $manifest->admin + ['slug' => $manifest->slug];
                }
            } catch (\Throwable $e) {
                $this->fail($report, $manifest->slug, $e);
            }
        }

        $this->registerAdminMenu($adminEntries);

        return $report;
    }

    /**
     * @param array{loaded: list<string>, failed: array<string,string>, skipped: bool} $report
     */
    private function loadMuPlugins(array &$report): void
    {
        if (!is_dir($this->muPluginsDir)) {
            return;
        }

        $files = glob($this->muPluginsDir . '/*.php') ?: [];
        sort($files);

        foreach ($files as $file) {
            $name = basename($file, '.php');
            $slug = 'mu-' . $name;

            try {
                $callable = (static fn (string $path) => include $path)($file);
                if (!is_callable($callable)) {
                    throw new InvalidPluginException(
                        'A mu-plugin file must return a callable(App $app, ContainerInterface $c).',
                    );
                }

                $callable($this->app, $this->container);
                $report['loaded'][] = $slug;
            } catch (\Throwable $e) {
                $this->fail($report, $slug, $e);
            }
        }
    }

    /**
     * `requires_plugin` is resolved against the manifests that actually loaded
     * (enabled, compatible, parseable) — not against the activation list, where
     * a broken dependency could still look present.
     *
     * @param array<string, PluginManifest> $available
     */
    private static function dependencyProblem(PluginManifest $manifest, array $available): ?string
    {
        foreach ($manifest->requiresPlugin as $slug => $constraint) {
            $dependency = $available[$slug] ?? null;
            if ($dependency === null) {
                return sprintf('Requires plugin "%s" (not enabled or failed to load).', $slug);
            }

            if (!PluginManifest::versionSatisfies($dependency->version, $constraint)) {
                return sprintf(
                    'Requires plugin "%s" %s (running %s).',
                    $slug,
                    (string) $constraint,
                    $dependency->version,
                );
            }
        }

        return null;
    }

    /**
     * One filter for all declarative entries. Group id unknown or group without
     * children → the entry becomes a top-level link instead of disappearing.
     *
     * @param list<array<string, string|null>> $entries
     */
    private function registerAdminMenu(array $entries): void
    {
        if ($entries === []) {
            return;
        }

        try {
            $events = $this->container->get(EventDispatcherInterface::class);
        } catch (\Throwable) {
            return;
        }

        if (!$events instanceof EventDispatcherInterface) {
            return;
        }

        $events->addFilter('admin_nav_groups', function (array $groups) use ($entries): array {
            $groups     = self::injectAdminEntries($groups, $entries);
            $savedOrder = $this->savedNavOrder();

            return $savedOrder === [] ? $groups : self::applySavedOrder($groups, $savedOrder);
        }, 20);
    }

    /**
     * @param list<array<string, mixed>> $groups
     * @param list<array<string, string|null>> $entries
     * @return list<array<string, mixed>>
     */
    private static function injectAdminEntries(array $groups, array $entries): array
    {
        foreach ($entries as $entry) {
            $item = [
                'id'         => 'plugin-' . (string) ($entry['slug'] ?? ''),
                'type'       => 'link',
                'label'      => (string) ($entry['label'] ?? ''),
                'href'       => (string) ($entry['href'] ?? ''),
                'icon'       => (string) ($entry['icon'] ?? ''),
                'permission' => $entry['permission'] ?? null,
            ];

            $target = $entry['group'] ?? null;
            $placed = false;

            if ($target !== null) {
                foreach ($groups as $index => $group) {
                    $children = $group['children'] ?? null;
                    if (($group['id'] ?? '') === $target && is_array($children)) {
                        $groups[$index]['children'][] = $item;
                        $placed                       = true;
                        break;
                    }
                }
            }

            if (!$placed) {
                $groups[] = $item;
            }
        }

        return $groups;
    }

    /**
     * Re-apply the saved top-level order once plugin entries are injected. PHP's
     * sort is stable, so ids the saved order does not know keep their relative
     * positions at the end.
     *
     * @param list<array<string, mixed>> $groups
     * @param list<string> $savedOrder
     * @return list<array<string, mixed>>
     */
    private static function applySavedOrder(array $groups, array $savedOrder): array
    {
        $positions = array_flip($savedOrder);

        usort($groups, static function (array $a, array $b) use ($positions): int {
            return ($positions[$a['id'] ?? ''] ?? PHP_INT_MAX) <=> ($positions[$b['id'] ?? ''] ?? PHP_INT_MAX);
        });

        return $groups;
    }

    /**
     * @return list<string>
     */
    private function savedNavOrder(): array
    {
        try {
            $settings = $this->container->get(SettingsRepositoryInterface::class);
        } catch (\Throwable) {
            return [];
        }

        if (!$settings instanceof SettingsRepositoryInterface) {
            return [];
        }

        $decoded = json_decode($settings->get('admin_nav_order', '') ?? '', true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_string'));
    }

    private function registerAutoloader(PluginManifest $manifest): void
    {
        $sourceDir = $manifest->directory . '/src';
        if (!is_dir($sourceDir)) {
            return;
        }

        $prefix = rtrim($manifest->namespace, '\\') . '\\';

        spl_autoload_register(static function (string $class) use ($sourceDir, $prefix): void {
            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
            $file     = $sourceDir . '/' . $relative . '.php';

            if (is_file($file)) {
                require $file;
            }
        });
    }

    /**
     * Expose content/plugins/<slug>/templates/ as Twig namespace @<slug>.
     * Front-end template overrides are intentionally not supported yet.
     */
    private function registerTemplateNamespace(PluginManifest $manifest): void
    {
        if ($this->twig === null) {
            return;
        }

        $templates = $manifest->directory . '/templates';
        if (!is_dir($templates)) {
            return;
        }

        $loader = $this->twig->getEnvironment()->getLoader();
        if ($loader instanceof FilesystemLoader) {
            $loader->addPath($templates, $manifest->slug);
        }
    }

    /**
     * @param array{loaded: list<string>, failed: array<string,string>, skipped: bool} $report
     */
    private function fail(array &$report, string $slug, \Throwable $e): void
    {
        $message = $e instanceof InvalidPluginException
            ? $e->getMessage()
            : $e::class . ': ' . $e->getMessage();

        $report['failed'][$slug] = $message;
        $this->plugins->recordFailure($slug, $message);

        error_log(sprintf('[Vale plugin] %s failed: %s', $slug, $message));
    }
}
