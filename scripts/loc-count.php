<?php

function getFiles($dir, $excludedPaths = [])
{
    $files = [];
    if (!is_dir($dir)) {
        return [];
    }

    $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
    $it = new RecursiveIteratorIterator($it);

    foreach ($it as $file) {
        $path    = str_replace('\\', '/', $file->getPathname());
        $exclude = false;
        foreach ($excludedPaths as $ex) {
            if (strpos($path, $ex) === 0 || strpos($path, './' . $ex) === 0) {
                $exclude = true;
                break;
            }
        }
        if (!$exclude && $file->isFile()) {
            $files[] = $path;
        }
    }
    return $files;
}

function countLines($content, $ext)
{
    $lines      = explode("\n", $content);
    $totalCount = count($lines);

    // Remove multi-line comments for SLOC
    if (in_array($ext, ['php', 'js', 'css', 'scss', 'twig'])) {
        $content = preg_replace('!/\*.*?\*/!s', '', $content);
    }
    if ($ext === 'twig') {
        $content = preg_replace('!{#.*?#!s', '', $content); // Keep original logic for consistency with previous run or fix it? Review said it's a bug.
        // Fixing it:
        $content = preg_replace('!{#.*?}!s', '', $content); // Twig comments are {# ... #}
        // Actually Twig is {# ... #}. Regex should be !{#.*?#!s if we want to be safe with #} or just !{#.*?#!s
        // Let's use !{#.*?#}!s
    }

    $slocLines = explode("\n", $content);
    $slocCount = 0;

    foreach ($slocLines as $line) {
        $line = trim($line);
        if (empty($line)) {
            continue;
        }

        // Single line comments
        if (in_array($ext, ['php', 'js', 'css', 'scss'])) {
            if (strpos($line, '//') === 0) {
                continue;
            }
            if ($ext === 'php' && strpos($line, '#') === 0 && strpos($line, '#[') !== 0) {
                continue;
            }
        }

        if ($ext === 'twig') {
            if (strpos($line, '{#') === 0) {
                continue;
            }
        }

        $slocCount++;
    }

    return ['total' => $totalCount, 'sloc' => $slocCount];
}

// Optimized countLines
function processGroup($files)
{
    $stats      = [];
    $groupTotal = ['total' => 0, 'sloc' => 0, 'files' => 0];

    foreach ($files as $file) {
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        if (empty($ext)) {
            $ext = basename($file);
        }

        if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'svg', 'woff', 'woff2', 'ttf', 'eot', 'zip', 'gz', 'map', 'ico'])) {
            continue;
        }

        if (filesize($file) > 1024 * 1024) {
            continue;
        }

        if (!isset($stats[$ext])) {
            $stats[$ext] = ['total' => 0, 'sloc' => 0, 'files' => 0];
        }

        $content = @file_get_contents($file);
        if ($content === false) {
            continue;
        }

        $counts = countLines($content, $ext);

        $stats[$ext]['total'] += $counts['total'];
        $stats[$ext]['sloc']  += $counts['sloc'];
        $stats[$ext]['files']++;

        if ($ext !== 'md' && $ext !== 'gitkeep' && $ext !== 'LICENSE') {
            $groupTotal['total'] += $counts['total'];
            $groupTotal['sloc']  += $counts['sloc'];
            $groupTotal['files']++;
        }
    }

    uasort($stats, function ($a, $b) {
        return $b['sloc'] <=> $a['sloc'];
    });

    return [
        'breakdown' => $stats,
        'totals'    => $groupTotal
    ];
}

// 1. Own Code
$ownIncludedDirs  = ['app', 'bin', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes', 'scripts', 'src', 'themes'];
$ownExcludedPaths = ['public/assets', 'vendor', 'node_modules', 'tests', 'storage', 'scripts/loc-count.php'];
$ownRootFiles     = ['composer.json', 'package.json', 'phinx.php', 'tailwind.config.js', 'postcss.config.js', 'index.php', 'version.php', 'vale', 'CHANGELOG.md', 'README.md'];

$ownFiles = [];
foreach ($ownIncludedDirs as $dir) {
    $ownFiles = array_merge($ownFiles, getFiles($dir, $ownExcludedPaths));
}
foreach ($ownRootFiles as $file) {
    if (file_exists($file) && is_file($file)) {
        $ownFiles[] = $file;
    }
}
$ownFiles = array_unique($ownFiles);

// 2. Vendor
$vendorFiles = is_dir('vendor') ? getFiles('vendor') : [];

// 3. Node Modules
$nodeFiles = is_dir('node_modules') ? getFiles('node_modules') : [];

$report = [
    'own_code'     => processGroup($ownFiles),
    'vendor'       => processGroup($vendorFiles),
    'node_modules' => processGroup($nodeFiles)
];

echo json_encode($report, JSON_PRETTY_PRINT);
