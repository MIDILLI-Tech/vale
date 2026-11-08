<?php

declare(strict_types=1);

namespace ValeApp\App\Commands;

use Slim\Views\Twig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\Markup;
use Twig\TwigFilter;
use Twig\TwigFunction;
use ValeApp\Infrastructure\Database\Connection;
use ValeApp\Infrastructure\Theme\ThemeManager;

final class CacheWarmCommand extends Command
{
    public function __construct(
        private readonly ?Twig $twig = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('cache:warm')
            ->setDescription('Pre-compile caches for faster production execution')
            ->addArgument('type', InputArgument::OPTIONAL, 'Cache type: container, views, or all', 'all')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be compiled without actually compiling');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $type   = $input->getArgument('type');
        $dryRun = (bool) $input->getOption('dry-run');

        $targets = match ($type) {
            'container' => ['container'],
            'views'     => ['views'],
            default     => ['container', 'views'],
        };

        if ($dryRun) {
            $output->writeln('<comment>DRY RUN — no files will be written</comment>');
        }

        foreach ($targets as $target) {
            match ($target) {
                'container' => $this->warmContainer($output, $dryRun),
                'views'     => $this->warmViews($output, $dryRun),
            };
        }

        $output->writeln($dryRun
            ? '<comment>Dry run complete. Run without --dry-run to actually compile.</comment>'
            : '<info>Done. Cache warmed successfully.</info>');

        return Command::SUCCESS;
    }

    private function warmContainer(OutputInterface $output, bool $dryRun): void
    {
        $compileDir = base_path('storage/cache/container');

        if ($dryRun) {
            $output->writeln("  <comment>container:</comment> would compile to {$compileDir}");
            return;
        }

        if (!is_dir($compileDir)) {
            @mkdir($compileDir, 0755, true);
        }

        $builder = new \DI\ContainerBuilder();
        $builder->addDefinitions(base_path('config/services.php'));
        $builder->enableCompilation($compileDir);

        ob_start();
        $builder->build();
        ob_end_clean();

        $output->writeln('  <comment>container:</comment> compiled');
    }

    private function warmViews(OutputInterface $output, bool $dryRun): void
    {
        if ($this->twig !== null) {
            $env = $this->twig->getEnvironment();
        } else {
            $appCfg   = require base_path('config/app.php');
            $cacheCfg = require base_path('config/cache.php');

            $activeTheme  = $this->resolveActiveTheme();
            $themeManager = new ThemeManager(
                $appCfg['paths']['themes'],
                $activeTheme,
            );

            $paths = $themeManager->buildTemplatePaths(
                $appCfg['paths']['resources'] . '/views',
            );

            $loader = new FilesystemLoader($paths);
            $env    = new Environment($loader, [
                'cache'       => $cacheCfg['views'],
                'auto_reload' => false,
            ]);

            $this->registerTwigStubs($env);
        }

        $paths = $env->getLoader()->getPaths();

        $seen  = [];
        $count = 0;

        foreach ($paths as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'twig') {
                    continue;
                }

                $name = $iterator->getSubPathname();
                $name = str_replace('\\', '/', $name);

                if (isset($seen[$name])) {
                    continue;
                }
                $seen[$name] = true;

                if ($dryRun) {
                    $count++;
                    continue;
                }

                try {
                    $env->load($name);
                    $count++;
                } catch (\Throwable $e) {
                    $output->writeln("  <comment>views:</comment> skipped {$name} ({$e->getMessage()})");
                }
            }
        }

        $output->writeln($dryRun
            ? "  <comment>views:</comment> would pre-compile {$count} templates"
            : "  <comment>views:</comment> pre-compiled {$count} templates");
    }

    private function registerTwigStubs(Environment $env): void
    {
        $stub     = fn () => '';
        $htmlStub = fn () => new Markup('', 'UTF-8');

        // Dynamic callbacks so any custom filter or function introduced in templates
        // is automatically stubbed during cache warming without needing manual hardcoded declarations.
        $env->registerUndefinedFunctionCallback(static function (string $name) use ($stub, $htmlStub): TwigFunction|false {
            if (in_array($name, ['csrf_field', 'theme_hook'], true)) {
                return new TwigFunction($name, $htmlStub, ['is_safe' => ['html']]);
            }
            return new TwigFunction($name, $stub);
        });

        $env->registerUndefinedFilterCallback(static function (string $name) use ($stub): TwigFilter|false {
            if (in_array($name, ['changelog', 'shortcodes'], true)) {
                return new TwigFilter($name, $stub, ['is_safe' => ['html']]);
            }
            if ($name === 'more_teaser') {
                return new TwigFilter($name, fn (): array => [
                    'teaser' => '', 'label' => null, 'has_more' => false,
                ]);
            }
            if ($name === 'post_teaser') {
                return new TwigFilter($name, fn (): array => [
                    'html' => '', 'kind' => 'empty', 'label' => null,
                ]);
            }
            if ($name === 'meta_teaser') {
                return new TwigFilter($name, fn (): string => '');
            }
            return new TwigFilter($name, $stub);
        });

        $env->addFunction(new TwigFunction('__', $stub));
        $env->addFunction(new TwigFunction('current_locale', $stub));
        $env->addFunction(new TwigFunction('csrf_field', $htmlStub, ['is_safe' => ['html']]));
        $env->addFunction(new TwigFunction('csrf_token', $stub));
        $env->addFunction(new TwigFunction('theme_hook', $htmlStub, ['is_safe' => ['html']]));
        $env->addFunction(new TwigFunction('url', $stub));
        $env->addFunction(new TwigFunction('locale_name', $stub));
        $env->addFunction(new TwigFunction('country_name', $stub));
        $env->addFunction(new TwigFunction('language_name', $stub));
        $env->addFunction(new TwigFunction('site_url', $stub));
        $env->addFunction(new TwigFunction('absolute_url', $stub));
        $env->addFunction(new TwigFunction('get_media_url', $stub));
        $env->addFunction(new TwigFunction('asset', $stub));
        $env->addFunction(new TwigFunction('env', $stub));
        $env->addFunction(new TwigFunction('vale_version', $stub));
        $env->addFunction(new TwigFunction('format_bytes', $stub));
        $env->addFunction(new TwigFunction('resolveIcon', $stub));

        $env->addFilter(new TwigFilter('changelog', $stub, ['is_safe' => ['html']]));
        $env->addFilter(new TwigFilter('shortcodes', $stub, ['is_safe' => ['html']]));
        $env->addFilter(new TwigFilter('local_date', $stub));
        $env->addFilter(new TwigFilter('iso_date', $stub));
        $env->addFilter(new TwigFilter('price', $stub));
        $env->addFilter(new TwigFilter('num', $stub));
        $env->addFilter(new TwigFilter('read_time', $stub));
        $env->addFilter(new TwigFilter('more_teaser', fn (): array => [
            'teaser' => '', 'label' => null, 'has_more' => false,
        ]));
        $env->addFilter(new TwigFilter('post_teaser', fn (): array => [
            'html' => '', 'kind' => 'empty', 'label' => null,
        ]));
        $env->addFilter(new TwigFilter('meta_teaser', fn (): string => ''));
    }

    private function resolveActiveTheme(): string
    {
        try {
            $conn  = Connection::createFromEnv();
            $value = $conn->fetchOne(
                'SELECT ' . $conn->quoteIdentifier('value') . ' FROM ' . VL_DB_PREFIX . 'settings WHERE ' . $conn->quoteIdentifier('key') . ' = ?',
                ['active_theme'],
            );
            return is_string($value) && $value !== '' ? $value : 'default';
        } catch (\Throwable) {
            return 'default';
        }
    }
}
