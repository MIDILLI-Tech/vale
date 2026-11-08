<?php

declare(strict_types=1);

/**
 * Vale Translation Management Script
 *
 * Usage:
 *   php scripts/manage-translations.php sync           - Syncs keys from en.json to other languages (adds missing, removes orphaned)
 *   php scripts/manage-translations.php create {code}  - Creates a new language file from en.json
 */

$root    = dirname(__DIR__);
$langDir = $root . '/resources/lang';
$enFile  = $langDir . '/en.json';

if (!file_exists($enFile)) {
    die("Error: en.json not found. Run composer translations first.\n");
}

$enData  = json_decode(file_get_contents($enFile), true);
$command = $argv[1] ?? null;

if ($command === 'sync') {
    $files = glob($langDir . '/*.json');
    foreach ($files as $file) {
        if (basename($file) === 'en.json') {
            continue;
        }

        $locale = basename($file, '.json');
        echo "Syncing $locale.json...\n";

        $data         = json_decode(file_get_contents($file), true) ?: [];
        $newCount     = 0;
        $removedCount = 0;

        foreach ($enData as $key => $value) {
            if (!isset($data[$key])) {
                $data[$key] = "";
                $newCount++;
            }
        }

        // Remove keys that no longer exist in en.json so language files stay in sync.
        foreach ($data as $key => $value) {
            if (!isset($enData[$key])) {
                unset($data[$key]);
                $removedCount++;
            }
        }

        ksort($data);
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo "  Added $newCount missing keys to $locale.json.\n";
        if ($removedCount > 0) {
            echo "  Removed $removedCount orphaned keys from $locale.json.\n";
        }
    }
} elseif ($command === 'create' && isset($argv[2])) {
    $locale     = $argv[2];
    $targetFile = $langDir . '/' . $locale . '.json';

    if (file_exists($targetFile)) {
        die("Error: $targetFile already exists.\n");
    }

    echo "Creating $locale.json from en.json...\n";
    $newData = [];
    foreach ($enData as $key => $value) {
        $newData[$key] = "";
    }

    ksort($newData);
    file_put_contents($targetFile, json_encode($newData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "Done.\n";
} else {
    echo "Usage:\n";
    echo "  php scripts/manage-translations.php sync\n";
    echo "  php scripts/manage-translations.php create {code}\n";
}
