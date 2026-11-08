<?php

/**
 * Run PHP Code Beautifier and Fixer with project standard
 */

$repoRoot = dirname(__DIR__, 2);
chdir($repoRoot);

$phpcbf = $repoRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpcbf';
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    $phpcbf_bin = $phpcbf . '.bat';
    if (!file_exists($phpcbf_bin)) {
        $phpcbf_bin = $phpcbf;
    }
} else {
    $phpcbf_bin = $phpcbf;
}

if (!file_exists($phpcbf_bin)) {
    fwrite(STDERR, "phpcbf binary not found. Run composer install first.\n");
    exit(1);
}

$config = __DIR__ . DIRECTORY_SEPARATOR . '.phpcs.xml.dist';
$args   = array_slice($argv, 1);

// Build command
$cmd = escapeshellarg($phpcbf_bin) . " --standard=" . escapeshellarg($config);
foreach ($args as $arg) {
    $cmd .= " " . escapeshellarg($arg);
}

passthru($cmd, $exitCode);
exit($exitCode);
