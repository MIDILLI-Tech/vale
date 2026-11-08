<?php

/**
 * Run PHP_CodeSniffer with project standard
 */

$repoRoot = dirname(__DIR__, 2);
chdir($repoRoot);

$phpcs = $repoRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpcs';
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    $phpcs_bin = $phpcs . '.bat';
    if (!file_exists($phpcs_bin)) {
        $phpcs_bin = $phpcs;
    }
} else {
    $phpcs_bin = $phpcs;
}

if (!file_exists($phpcs_bin)) {
    fwrite(STDERR, "phpcs binary not found. Run composer install first.\n");
    exit(1);
}

$config = __DIR__ . DIRECTORY_SEPARATOR . '.phpcs.xml.dist';
$args   = array_slice($argv, 1);

// Build command
$cmd = escapeshellarg($phpcs_bin) . " --standard=" . escapeshellarg($config);
foreach ($args as $arg) {
    $cmd .= " " . escapeshellarg($arg);
}

passthru($cmd, $exitCode);
exit($exitCode);
