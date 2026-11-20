#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * scripts/bump-version.php
 *
 * Local maintenance helper — updates the version strings in the working tree.
 * It never commits, tags or pushes; orchestrate-release.php owns releases.
 *
 * Usage:
 *   php scripts/bump-version.php --version=0.79.0
 *   php scripts/bump-version.php --patch
 *   php scripts/bump-version.php --minor --changelog
 *
 * Options:
 *   --version=<x.y.z>        Target version
 *   --patch|--minor|--major  Increment the version.php value instead
 *   --changelog              Promote "## [Unreleased]" to "## [<version>] - <date>"
 *                            and leave a fresh empty Unreleased section above it
 *   --dry-run                Show what would change without writing anything
 *
 * Files touched:
 *   version.php   → return '<version>';
 *   README.md     → the version badge URL and its alt text
 *   CHANGELOG.md  → only with --changelog
 *
 * Exit codes:
 *   0  success
 *   1  error (printed to STDERR)
 */

$rootDir = dirname(__DIR__);
chdir($rootDir);

$versionTemplate = "<?php\n// Auto-updated by scripts/prepare-release.php during release builds\nreturn '%s';\n";

$opts = getopt('', ['version:', 'patch', 'minor', 'major', 'changelog', 'dry-run']);

if ($opts === false) {
    fail('Could not parse options.');
}

$dryRun = isset($opts['dry-run']);

// ── Resolve current version ──────────────────────────────────────────────────

$versionFile = $rootDir . '/version.php';
if (!is_file($versionFile)) {
    fail('version.php not found.');
}

$current = require $versionFile;
if (!is_string($current) || preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $current, $parts) !== 1) {
    fail('version.php must return a plain x.y.z version (found: ' . var_export($current, true) . ').');
}

// ── Resolve target version ───────────────────────────────────────────────────

$bumpFlags = array_intersect_key($opts, ['patch' => true, 'minor' => true, 'major' => true]);

if (count($bumpFlags) > 1) {
    fail('Use only one of --major, --minor or --patch.');
}

if (isset($opts['version']) && $bumpFlags !== []) {
    fail('--version cannot be combined with --major/--minor/--patch.');
}

if (isset($opts['version'])) {
    $target = trim((string) $opts['version']);
} elseif (isset($opts['major'])) {
    $target = ((int) $parts[1] + 1) . '.0.0';
} elseif (isset($opts['minor'])) {
    $target = $parts[1] . '.' . ((int) $parts[2] + 1) . '.0';
} elseif (isset($opts['patch'])) {
    $target = $parts[1] . '.' . $parts[2] . '.' . ((int) $parts[3] + 1);
} else {
    fail('Pass --version=x.y.z or one of --major, --minor, --patch.');
}

if (preg_match('/^\d+\.\d+\.\d+$/', $target) !== 1) {
    fail("Version \"{$target}\" is not a plain semver string (x.y.z).");
}

info('Vale version bump');
info('=================');
info("Current : {$current}");
info("Target  : {$target}");
info('Dry run : ' . ($dryRun ? 'YES' : 'no'));
info('');

// ── version.php ──────────────────────────────────────────────────────────────

$targetContent = sprintf($versionTemplate, $target);

if (file_get_contents($versionFile) === $targetContent) {
    info("version.php already at {$target}.");
} elseif ($dryRun) {
    info("[DRY RUN] Would update version.php → '{$target}'");
} else {
    file_put_contents($versionFile, $targetContent);
    info("version.php updated → '{$target}'");
}

// ── README.md badge ──────────────────────────────────────────────────────────

$readmeFile = $rootDir . '/README.md';
if (!is_file($readmeFile)) {
    fail('README.md not found.');
}

$readme        = (string) file_get_contents($readmeFile);
$updatedReadme = preg_replace(
    ['/(version-)(\d+\.\d+\.\d+)(-blue)/', '/(alt="Version )(\d+\.\d+\.\d+)(")/'],
    ['${1}' . $target . '${3}', '${1}' . $target . '${3}'],
    $readme
);

if ($updatedReadme === null) {
    fail('README.md version badge could not be replaced.');
}

if ($updatedReadme === $readme) {
    info('README.md version badge already up to date.');
} elseif ($dryRun) {
    info("[DRY RUN] Would update README.md version badge → '{$target}'");
} else {
    file_put_contents($readmeFile, $updatedReadme);
    info("README.md version badge updated → '{$target}'");
}

// ── CHANGELOG.md (optional) ──────────────────────────────────────────────────

if (isset($opts['changelog'])) {
    updateChangelog($rootDir . '/CHANGELOG.md', $target, $dryRun);
}

info('');
info('Done. Nothing was committed — commit the files yourself when you are ready.');

exit(0);

// ── Helpers ──────────────────────────────────────────────────────────────────

function info(string $message): void
{
    echo $message . "\n";
}

function fail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

function updateChangelog(string $file, string $version, bool $dryRun): void
{
    if (!is_file($file)) {
        fail('CHANGELOG.md not found.');
    }

    $text = str_replace("\r\n", "\n", (string) file_get_contents($file));

    if (preg_match('/^## \[' . preg_quote($version, '/') . '\]/m', $text) === 1) {
        info("CHANGELOG.md already has a [{$version}] section — untouched.");
        return;
    }

    $heading = "## [{$version}] - " . date('Y-m-d');
    $count   = 0;

    $updated = preg_replace(
        '/^## \[Unreleased\][^\n]*$/m',
        "## [Unreleased]\n\n" . $heading,
        $text,
        1,
        $count
    );

    if ($updated === null) {
        fail('CHANGELOG.md could not be updated.');
    }

    if ($count === 0) {
        if (preg_match('/^## \[/m', $text, $firstHeading, PREG_OFFSET_CAPTURE) === 1) {
            $offset  = (int) $firstHeading[0][1];
            $updated = substr($text, 0, $offset) . $heading . "\n\n" . substr($text, $offset);
        } else {
            $updated = rtrim($text) . "\n\n" . $heading . "\n";
        }
    }

    if ($dryRun) {
        info("[DRY RUN] Would prepare CHANGELOG.md section: {$heading}");
        return;
    }

    file_put_contents($file, $updated);
    info("CHANGELOG.md section prepared: {$heading}");
}
