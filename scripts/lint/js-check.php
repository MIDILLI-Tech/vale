<?php

declare(strict_types=1);

/**
 * JavaScript syntax check.
 *
 * Checks standalone .js files and the inline <script> blocks embedded in Twig
 * templates. Twig tags inside scripts are replaced with neutral placeholders so
 * node can parse the resulting JavaScript.
 *
 * Usage:
 *   php scripts/lint/js-check.php              check all .js and .twig files
 *   php scripts/lint/js-check.php <file...>    check only the given files
 */
$repoRoot = dirname(__DIR__, 2);
chdir($repoRoot);

/**
 * Replace Twig {{ }} and {% %} tags with neutral placeholders, respecting
 * single- and double-quoted string literals inside the tag so that a tag like
 * {{ '}}' }} is matched as a whole.
 */
function sanitizeTwigJs(string $source): string
{
    $result = '';
    $len    = strlen($source);
    $i      = 0;
    while ($i < $len) {
        if ($source[$i] === '{' && $i + 1 < $len && ($source[$i + 1] === '{' || $source[$i + 1] === '%')) {
            $open        = $source[$i + 1];
            $close       = $open === '{' ? '}}' : '%}';
            $replacement = $open === '{' ? '0' : '';
            // Find the matching close, respecting quoted strings.
            $j        = $i + 2;
            $inSingle = false;
            $inDouble = false;
            $found    = false;
            while ($j < $len) {
                $c = $source[$j];
                if ($inSingle) {
                    if ($c === '\\') {
                        $j += 2;
                        continue;
                    }
                    if ($c === "'") {
                        $inSingle = false;
                    }
                    $j++;
                    continue;
                }
                if ($inDouble) {
                    if ($c === '\\') {
                        $j += 2;
                        continue;
                    }
                    if ($c === '"') {
                        $inDouble = false;
                    }
                    $j++;
                    continue;
                }
                if ($c === "'") {
                    $inSingle = true;
                    $j++;
                    continue;
                }
                if ($c === '"') {
                    $inDouble = true;
                    $j++;
                    continue;
                }
                if (substr($source, $j, 2) === $close) {
                    $found = true;
                    break;
                }
                $j++;
            }
            if ($found) {
                $result .= $replacement;
                $i = $j + 2;
            } else {
                // No closing tag; copy rest verbatim.
                $result .= substr($source, $i);
                break;
            }
        } else {
            $result .= $source[$i];
            $i++;
        }
    }
    return $result;
}

if (trim((string) shell_exec('node --version 2>&1')) === '') {
    fwrite(STDERR, "Node.js is not available; skipping JS syntax check.\n");
    exit(0);
}

$files = array_slice($argv, 1);
if ($files === []) {
    $roots     = [$repoRoot . '/resources/js', $repoRoot . '/public/assets/js', $repoRoot . '/resources/views'];
    $themesDir = $repoRoot . '/themes';
    if (is_dir($themesDir)) {
        foreach (glob($themesDir . '/*/templates', GLOB_ONLYDIR) ?: [] as $dir) {
            $roots[] = $dir;
        }
    }
    foreach ($roots as $root) {
        if (!is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $name = $file->getFilename();
            if (str_ends_with($name, '.js') || str_ends_with($name, '.twig')) {
                $files[] = $file->getPathname();
            }
        }
    }
    sort($files);
}

if ($files === []) {
    echo "No JS or Twig files found. Nothing to check.\n";
    exit(0);
}

$failed  = 0;
$checked = 0;

foreach ($files as $file) {
    if (!is_readable($file)) {
        fwrite(STDERR, "Warning: File not found or unreadable, skipping: {$file}\n");
        continue;
    }

    $content = (string) file_get_contents($file);

    if (str_ends_with($file, '.twig')) {
        preg_match_all('/<script\b([^>]*)>([\s\S]*?)<\/script>/i', $content, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as $index => $fullMatch) {
            $attrs  = $matches[1][$index][0];
            $script = $matches[2][$index][0];

            if (preg_match('/\btype\s*=\s*["\']?([^"\'>\s]+)/i', $attrs, $typeMatch)) {
                $type = strtolower($typeMatch[1]);
                if (!in_array($type, ['', 'text/javascript', 'application/javascript', 'module', 'text/ecmascript'], true)) {
                    continue;
                }
            }

            $sanitized = sanitizeTwigJs($script);

            if (trim($sanitized) === '') {
                continue;
            }

            $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jscheck_' . uniqid() . '.js';
            file_put_contents($tmp, $sanitized);
            $output   = [];
            $exitCode = 0;
            exec('node --check ' . escapeshellarg($tmp) . ' 2>&1', $output, $exitCode);
            unlink($tmp);

            $checked++;
            if ($exitCode !== 0) {
                $failed++;
                echo "FAIL {$file} (inline script #" . ($index + 1) . ")\n";
                foreach ($output as $line) {
                    echo "  {$line}\n";
                }
            }
        }
        continue;
    }

    // Standalone .js file
    $output   = [];
    $exitCode = 0;
    exec('node --check ' . escapeshellarg($file) . ' 2>&1', $output, $exitCode);
    $checked++;
    if ($exitCode !== 0) {
        $failed++;
        echo "FAIL {$file}\n";
        foreach ($output as $line) {
            echo "  {$line}\n";
        }
    }
}

if ($failed > 0) {
    echo "\nJS syntax check failed: {$failed} file(s) with errors ({$checked} checked).\n";
    exit(1);
}

echo "JS syntax check passed: {$checked} script(s) checked.\n";
exit(0);
