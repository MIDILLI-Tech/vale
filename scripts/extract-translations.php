<?php

declare(strict_types=1);

/**
 * Vale Translation Extraction Script
 *
 * Scans PHP, Twig and JS files for translation keys, adds missing keys to
 * en.json and reports (or removes) unused ones.
 *
 * Usage:
 *   php scripts/extract-translations.php                  Add missing keys; report unused (no removal)
 *   php scripts/extract-translations.php --prune          Also remove unused keys from ALL lang files
 *   php scripts/extract-translations.php --check          CI mode: writes nothing, exits 1 when keys are missing/unused
 */

$root          = dirname(__DIR__);
$langDir       = $root . '/resources/lang';
$enFile        = $langDir . '/en.json';
$preserveFile  = __DIR__ . '/translation-preserve.php';

$pathsToScan = [
    $root . '/src',
    $root . '/app',
    $root . '/routes',
    $root . '/resources/views',
    $root . '/resources/js',
    $root . '/themes',
];

$prune = in_array('--prune', $argv, true);
$check = in_array('--check', $argv, true);

if (!file_exists($enFile)) {
    die("Error: en.json not found at $enFile\n");
}

$preserve = file_exists($preserveFile) ? (require $preserveFile) : [];
$preserve = array_fill_keys(array_map('strval', $preserve), true);

// ── Static key extraction ──────────────────────────────────────────────
// PHP/Twig: __('key') / __("key") / $__('key') — also matches ->__(...)
$phpRegex       = '/(?:__)\(\s*\'((?:\\\\.|[^\'])*)\'\s*(?:,|\))/s';
$phpRegexDouble = '/(?:__)\(\s*"((?:\\\\.|[^"])*)"\s*(?:,|\))/s';

// PHP: $this->translator->get('key') / $translator->get("key") — same as __()
// (generic ->get() calls such as settings, session or Slim routes are NOT translations)
$translatorRegex       = '/translator\s*->\s*get\(\s*\'((?:\\\\.|[^\'])*)\'\s*(?:,|\))/s';
$translatorRegexDouble = '/translator\s*->\s*get\(\s*"((?:\\\\.|[^"])*)"\s*(?:,|\))/s';

// JS: tjs('key') / tjs("key")
$jsRegex       = '/tjs\(\s*\'((?:\\\\.|[^\'])*)\'\s*(?:,|\))/s';
$jsRegexDouble = '/tjs\(\s*"((?:\\\\.|[^"])*)"\s*(?:,|\))/s';

// Dynamic __(<variable>) usages — cannot be resolved statically, reported for guidance only
$dynamicRegex = '/(?<![$\w])__\(\s*([$\w][\w.\-]*)/';

$foundKeys    = [];
$dynamicUsage = [];

foreach ($pathsToScan as $path) {
    if (!is_dir($path)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isDir()) {
            continue;
        }

        $filePath  = $file->getPathname();
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        if (!in_array($extension, ['php', 'twig', 'js'], true)) {
            continue;
        }

        $content = file_get_contents($filePath);

        if ($extension === 'php' || $extension === 'twig') {
            $regexes = [$phpRegex, $phpRegexDouble];
            if ($extension === 'php') {
                $regexes = array_merge($regexes, [$translatorRegex, $translatorRegexDouble]);
            }

            foreach ($regexes as $regex) {
                if (preg_match_all($regex, $content, $matches)) {
                    foreach ($matches[1] as $key) {
                        $foundKeys[unescapeKey($key, str_contains($regex, '"'))] = true;
                    }
                }
            }

            if (preg_match_all($dynamicRegex, $content, $matches)) {
                foreach ($matches[1] as $expr) {
                    $dynamicUsage[$expr] ??= [];
                    $dynamicUsage[$expr][] = $filePath;
                }
            }

            // tjs() calls inside Twig inline <script> blocks
            foreach ([$jsRegex, $jsRegexDouble] as $regex) {
                if (preg_match_all($regex, $content, $matches)) {
                    foreach ($matches[1] as $key) {
                        $foundKeys[unescapeKey($key, $regex === $jsRegexDouble)] = true;
                    }
                }
            }
        } elseif ($extension === 'js') {
            foreach ([$jsRegex, $jsRegexDouble] as $regex) {
                if (preg_match_all($regex, $content, $matches)) {
                    foreach ($matches[1] as $key) {
                        $foundKeys[unescapeKey($key, $regex === $jsRegexDouble)] = true;
                    }
                }
            }
        }
    }
}

$foundKeys = array_fill_keys(array_keys($foundKeys), true);

// ── Analyse en.json ────────────────────────────────────────────────────
$enData = json_decode(file_get_contents($enFile), true);
if (!is_array($enData)) {
    die("Error: en.json is not a valid JSON object.\n");
}

$missing = [];
foreach ($foundKeys as $key => $_) {
    if (!array_key_exists($key, $enData)) {
        $missing[$key] = true;
    }
}

$missingPreserved = [];
foreach ($preserve as $key => $_) {
    if (!array_key_exists($key, $enData)) {
        $missingPreserved[$key] = true;
    }
}

$unused = [];
foreach (array_keys($enData) as $key) {
    if (!isset($foundKeys[$key]) && !isset($preserve[$key])) {
        $unused[$key] = true;
    }
}

// ── Output ─────────────────────────────────────────────────────────────
$countFound   = count($foundKeys);
$countUnused  = count($unused);
$countMissing = count($missing);

echo "Scanned for translation keys.\n";
echo "  Found       : $countFound unique keys in code\n";
echo "  en.json keys: " . count($enData) . "\n";
echo "  Missing     : $countMissing\n";
echo "  Unused      : $countUnused\n";

if (!empty($missing)) {
    $keys = array_keys($missing);
    sort($keys);
    echo "\nMissing keys in en.json:\n";
    foreach ($keys as $key) {
        echo "  + $key\n";
    }
}

if (!empty($missingPreserved)) {
    $keys = array_keys($missingPreserved);
    sort($keys);
    echo "\nDynamically referenced keys missing from en.json (on preserve list):\n";
    foreach ($keys as $key) {
        echo "  + $key\n";
    }
}

if (!empty($unused)) {
    $keys = array_keys($unused);
    sort($keys);
    echo "\nUnused keys in en.json:\n";
    foreach ($keys as $key) {
        echo "  - $key\n";
    }
}

if (!empty($dynamicUsage)) {
    $exprs = array_keys($dynamicUsage);
    sort($exprs);
    echo "\nDynamic __() usages (not statically resolvable):\n";
    foreach ($exprs as $expr) {
        echo "  $expr\n";
    }
}

// ── Check mode (CI) ────────────────────────────────────────────────────
if ($check) {
    echo "\n" . (($countMissing > 0 || count($missingPreserved) > 0 || $countUnused > 0)
        ? "CHECK FAILED: en.json is out of sync. Run: composer translations\n"
        : "CHECK PASSED: en.json is in sync.\n");
    exit(($countMissing > 0 || count($missingPreserved) > 0 || $countUnused > 0) ? 1 : 0);
}

// ── Apply changes ──────────────────────────────────────────────────────
$addedCount = 0;
foreach ($missing as $key => $_) {
    $enData[$key] = $key;
    $addedCount++;
}
foreach ($missingPreserved as $key => $_) {
    $enData[$key] = $key;
    $addedCount++;
}

$removedCount = 0;
if ($prune) {
    foreach ($unused as $key => $_) {
        unset($enData[$key]);
        $removedCount++;
    }
}

$jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

if ($addedCount > 0 || $removedCount > 0 || $prune) {
    ksort($enData);
    file_put_contents($enFile, json_encode($enData, $jsonFlags));

    if ($prune && $removedCount > 0) {
        // Keep other language files in sync with en.json.
        $syncRemoved = 0;
        foreach (glob($langDir . '/*.json') as $langFile) {
            if ($langFile === $enFile) {
                continue;
            }
            $langData = json_decode(file_get_contents($langFile), true);
            if (!is_array($langData)) {
                continue;
            }
            $changed = false;
            foreach (array_keys($unused) as $key) {
                if (array_key_exists($key, $langData)) {
                    unset($langData[$key]);
                    $changed = true;
                }
            }
            if ($changed) {
                ksort($langData);
                file_put_contents($langFile, json_encode($langData, $jsonFlags));
                $syncRemoved++;
            }
        }
        echo "\n  Removed unused keys from $syncRemoved other language file(s).\n";
    }
}

echo "\nDone.\n";

/**
 * Normalise a captured key literal: resolve escape sequences so the key
 * matches the string the runtime actually looks up.
 */
function unescapeKey(string $key, bool $doubleQuoted): string
{
    if ($doubleQuoted) {
        return str_replace(['\\"', '\\\\'], ['"', '\\'], $key);
    }

    return str_replace(["\\'", '\\\\'], ["'", '\\'], $key);
}
