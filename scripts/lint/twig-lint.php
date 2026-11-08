<?php

declare(strict_types=1);
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Twig template syntax check.
 *
 * Parses every Twig template under resources/views and the theme template
 * directories without rendering them. Catches unclosed tags, malformed
 * expressions and other syntax errors before they surface at runtime.
 *
 * Usage:
 *   php scripts/lint/twig-lint.php              lint all templates
 *   php scripts/lint/twig-lint.php <file...>     lint only the given files
 */

$repoRoot = dirname(__DIR__, 2);
chdir($repoRoot);

require $repoRoot . '/vendor/autoload.php';

use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Source;

$files = array_slice($argv, 1);
if ($files === []) {
    $roots     = [new RecursiveDirectoryIterator($repoRoot . '/resources/views')];
    $themesDir = $repoRoot . '/themes';
    if (is_dir($themesDir)) {
        foreach (glob($themesDir . '/*/templates', GLOB_ONLYDIR) ?: [] as $dir) {
            $roots[] = new RecursiveDirectoryIterator($dir);
        }
    }
    foreach ($roots as $root) {
        $iterator = new RecursiveIteratorIterator($root);
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.twig')) {
                $files[] = $file->getPathname();
            }
        }
    }
    sort($files);
}

if ($files === []) {
    echo "No Twig templates found. Nothing to lint.\n";
    exit(0);
}

$twig = new Environment(new ArrayLoader());

// Stub the project's custom filters/functions/globals so files that use them
// still parse. Stubs accept anything and return a neutral value; the linter
// only checks syntax, never renders.
$stubAny = static fn (): string => '';
foreach ([
    'changelog', 'local_date', 'iso_date', 'price', 'num', 'read_time',
    'compact_count', 'more_teaser', 'post_teaser', 'meta_teaser', 'shortcodes',
] as $filter) {
    $twig->addFilter(new TwigFilter($filter, $stubAny));
}
foreach ([
    'resolveIcon', '__', 'current_locale', 'csrf_field', 'csrf_token',
    'theme_hook', 'url', 'locale_name', 'country_name', 'language_name',
    'site_url', 'absolute_url', 'get_media_url', 'asset', 'env',
    'vale_version', 'format_bytes', 'admin_url', 'table', 'vl_setting',
    'flash', 'icon',
] as $function) {
    $twig->addFunction(new TwigFunction($function, $stubAny));
}
foreach (['site_name', 'current_currency', 'default_currency', 'fx_rate'] as $global) {
    $twig->addGlobal($global, '');
}

$failed = 0;
foreach ($files as $file) {
    if (!is_readable($file)) {
        fwrite(STDERR, "Warning: File not found or unreadable, skipping: {$file}\n");
        continue;
    }
    try {
        $source = new Source((string) file_get_contents($file), basename((string) $file), (string) $file);
        $twig->parse($twig->tokenize($source));
    } catch (Throwable $e) {
        $failed++;
        echo "FAIL {$file}: {$e->getMessage()}\n";
    }
}

if ($failed > 0) {
    echo "\nTwig lint failed: {$failed} file(s) with syntax errors.\n";
    exit(1);
}

echo 'Twig lint passed: ' . count($files) . " file(s) checked.\n";
exit(0);
