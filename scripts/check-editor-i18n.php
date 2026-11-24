<?php

declare(strict_types=1);

/**
 * Fails when an editor tjs() key has no window.VL_I18N entry or no catalog row.
 *
 * Usage: php scripts/check-editor-i18n.php
 */

$root       = dirname(__DIR__);
$editorDir  = $root . '/resources/js/editor';
$layoutFile = $root . '/resources/views/admin/layout.twig';

if (!is_dir($editorDir) || !is_file($layoutFile)) {
    fwrite(STDERR, "Cannot find resources/js/editor or resources/views/admin/layout.twig\n");
    exit(2);
}

$used = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($editorDir, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'js') {
        continue;
    }
    $source = (string) file_get_contents($file->getPathname());
    preg_match_all('/tjs\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/', $source, $single);
    preg_match_all('/tjs\(\s*"((?:[^"\\\\]|\\\\.)*)"/', $source, $double);
    foreach (array_merge($single[1], $double[1]) as $key) {
        $used[stripslashes($key)] = true;
    }
}
ksort($used);

$registered = [];
foreach (file($layoutFile) ?: [] as $line) {
    if (preg_match('/^\s*"((?:\\\\.|[^"])*)"\s*:\s*"\s*\{\{\s*__\(\'/', $line, $match)) {
        $decoded = json_decode('"' . $match[1] . '"');
        if (is_string($decoded)) {
            $registered[$decoded] = true;
        }
    }
}

$en = json_decode((string) file_get_contents($root . '/resources/lang/en.json'), true, 512, JSON_THROW_ON_ERROR);
$tr = json_decode((string) file_get_contents($root . '/resources/lang/tr.json'), true, 512, JSON_THROW_ON_ERROR);

$problems = [];
foreach (array_keys($used) as $key) {
    if (!isset($registered[$key])) {
        $problems[] = 'VL_I18N missing : ' . $key;
    }
    if (!isset($en[$key])) {
        $problems[] = 'en.json missing  : ' . $key;
    }
    if (!isset($tr[$key])) {
        $problems[] = 'tr.json missing  : ' . $key;
    }
}

printf("editor tjs() keys: %d\nVL_I18N entries : %d\n", count($used), count($registered));

if ($problems === []) {
    echo "OK: every editor string is registered and translated.\n";
    exit(0);
}

echo "\n" . implode("\n", $problems) . "\n";
exit(1);
