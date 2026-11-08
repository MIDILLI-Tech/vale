#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * scripts/prepare-release.php
 *
 * Build a Vale release ZIP suitable for the auto-update pipeline.
 *
 * Usage:
 *   php scripts/prepare-release.php [options]
 *
 * Options:
 *   --version=<x.y.z>      Override version (default: reads version.php)
 *   --output=<dir>          Output directory  (default: dist/)
 *   --compress              Enable DEFLATE compression (default: STORE)
 *   --include-vendor        Run `composer install --no-dev` and bundle vendor/
 *   --update-deps           Run `npm update` and `composer update` before building (default: false)
 *   --private-key=<b64>     Ed25519 private key (base64) — signs the release.
 *                           If omitted, UPDATE_PRIVATE_KEY_ED25519 from .env is used.
 *   --generate-key          Generate a new Ed25519 keypair and exit (first-time setup).
 *                           Private key → store in .env and CI secrets.
 *                           Public key  → set UPDATE_PUBLIC_KEY_ED25519 in every installation's .env.
 *   --dry-run               Show plan without creating any files
 *   --requires-migration    Force manifest requires_migration=true (normally
 *                           auto-detected from the migration files changed
 *                           since the previous release tag)
 *   --no-requires-migration Force manifest requires_migration=false
 *
 * Output (always printed, CI/CD friendly):
 *   ZIP path, SHA-256, manifest JSON snippet (+ signature when key is available)
 *
 * Extra artifacts:
 *   dist/vale-agents-<version>.zip — AI agent tooling (.agents/ + AGENTS.md),
 *   built automatically when those files exist. The main release ZIP always
 *   excludes them.
 *   dist/vale-integration-skills-<version>.zip — the vale-integrate skill on its
 *   own, for handing the REST API / Webhook integration workflow to an external
 *   developer without the full agents pack.
 *
 * Exit codes:
 *   0  success
 *   1  error (printed to STDERR)
 */

// ── Bootstrap ────────────────────────────────────────────────────────────────

$rootDir = dirname(__DIR__);
chdir($rootDir);

// ── Option parsing ───────────────────────────────────────────────────────────

$opts = getopt('', [
    'version:',
    'output:',
    'compress',
    'include-vendor',
    'update-deps',
    'private-key:',
    'generate-key',
    'dry-run',
    'requires-migration',
    'no-requires-migration',
]);

// ── --generate-key: first-time keypair setup ─────────────────────────────────

if (isset($opts['generate-key'])) {
    if (!extension_loaded('sodium')) {
        err('--generate-key requires the sodium extension.');
    }
    $keypair   = sodium_crypto_sign_keypair();
    $secretKey = sodium_crypto_sign_secretkey($keypair);
    $publicKey = sodium_crypto_sign_publickey($keypair);
    $secretB64 = base64_encode($secretKey);
    $publicB64 = base64_encode($publicKey);

    echo "Ed25519 Keypair Generated\n";
    echo "=========================\n\n";
    echo "PRIVATE KEY — keep secret, never commit. Add to CI/CD secrets and your own .env:\n";
    echo "UPDATE_PRIVATE_KEY_ED25519={$secretB64}\n\n";
    echo "PUBLIC KEY — add to UPDATE_PUBLIC_KEY_ED25519 in every installation's .env:\n";
    echo "UPDATE_PUBLIC_KEY_ED25519={$publicB64}\n\n";
    echo "This script will use UPDATE_PRIVATE_KEY_ED25519 automatically if set in .env or environment.\n";
    exit(0);
}

// ── Load .env (key=value, skip comments/blanks) ──────────────────────────────

$envFile = $rootDir . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $val] = explode('=', $line, 2);
        $key         = trim($key);
        $val         = trim($val);
        if (!isset($_ENV[$key]) && !isset($_SERVER[$key])) {
            $_ENV[$key] = $val;
        }
    }
}

$dryRun        = isset($opts['dry-run']);
$useCompress   = isset($opts['compress']);
$includeVendor = isset($opts['include-vendor']);
$updateDeps    = isset($opts['update-deps']);
$outputDir     = isset($opts['output']) ? rtrim((string) $opts['output'], '/\\') : $rootDir . '/dist';

// ── Load reference-data.php for dynamic requirements ───────────────────────────

$refData = require $rootDir . '/config/reference-data.php';
$minPhp  = $refData['requirements']['min_php'];

// min_extensions is derived from the centralized reference data so the manifest
// always reflects what the running Vale needs, regardless of the release path.
$minExtensions = array_values(array_keys($refData['requirements']['required_extensions'] ?? []));

// Private key: CLI arg → .env / environment variable → empty (no signing)
$privateKeyB64 = '';
if (isset($opts['private-key']) && (string) $opts['private-key'] !== '') {
    $privateKeyB64 = (string) $opts['private-key'];
} elseif (!empty($_ENV['UPDATE_PRIVATE_KEY_ED25519'])) {
    $privateKeyB64 = $_ENV['UPDATE_PRIVATE_KEY_ED25519'];
    if ($privateKeyB64 !== '') {
        info("Signing key loaded from .env (UPDATE_PRIVATE_KEY_ED25519)");
    }
} elseif (!empty(getenv('UPDATE_PRIVATE_KEY_ED25519'))) {
    $privateKeyB64 = getenv('UPDATE_PRIVATE_KEY_ED25519');
    if ($privateKeyB64 !== '') {
        info("Signing key loaded from environment (UPDATE_PRIVATE_KEY_ED25519)");
    }
}

// ── Resolve version ──────────────────────────────────────────────────────────

if (isset($opts['version'])) {
    $version = (string) $opts['version'];
} else {
    $versionFile = $rootDir . '/version.php';
    if (!file_exists($versionFile)) {
        err('version.php not found and --version not specified.');
    }
    $version = require $versionFile;
    if (!is_string($version) || $version === '') {
        err('version.php did not return a valid string.');
    }
}

if (!preg_match('/^\d+\.\d+\.\d+/', $version)) {
    err("Version \"{$version}\" does not look like a semver string (x.y.z).");
}

// ── Parse CHANGELOG.md (mandatory) ───────────────────────────────────────────

$changelogFile = $rootDir . '/CHANGELOG.md';
if (!file_exists($changelogFile)) {
    err('CHANGELOG.md not found. Add a changelog entry for this version before releasing.');
}

$changelogText = parseChangelog($changelogFile, $version);

// ── Migration detection ──────────────────────────────────────────────────────
// Informational only: the updater always runs `phinx migrate` after copying
// files, so this flag drives the admin warning, not the migration itself.

$migrationAdded   = [];
$migrationRemoved = [];
$migrationEdited  = [];

if (isset($opts['requires-migration'])) {
    $requiresMigration = true;
    $migrationNote     = 'forced by --requires-migration';
} elseif (isset($opts['no-requires-migration'])) {
    $requiresMigration = false;
    $migrationNote     = 'forced by --no-requires-migration';
} else {
    $detected = detectMigrationChanges($version);
    if ($detected === null) {
        $requiresMigration = false;
        $migrationNote     = 'unknown (no git history / previous tag)';
        fwrite(STDERR, "WARNING: cannot detect migration changes — {$migrationNote}."
            . " Pass --requires-migration if this release adds migrations.\n");
    } else {
        $migrationAdded    = $detected['added'];
        $migrationRemoved  = $detected['removed'];
        $migrationEdited   = $detected['edited'];
        $requiresMigration = $migrationAdded !== []
            || $migrationRemoved             !== []
            || $migrationEdited              !== [];
        $migrationNote     = "since {$detected['previous_tag']}: "
            . count($migrationAdded) . ' added, '
            . count($migrationRemoved) . ' removed, '
            . count($migrationEdited) . ' edited';

        // Editing an existing migration does not reach installs that already
        // ran it: Phinx only executes files it has not recorded yet. The flag
        // still warns admins about the schema change, so tell the release
        // engineer why nothing will be applied automatically.
        if ($migrationAdded === [] && $migrationEdited !== []) {
            fwrite(STDERR, "NOTE: migration file(s) edited without adding a new one —"
                . " Phinx will not re-run them on existing installations."
                . " Add a migration file if the columns must be created automatically.\n");
        }
    }
}

// ── Paths ────────────────────────────────────────────────────────────────────

$zipName    = "vale-{$version}.zip";
$zipPath    = $outputDir . '/' . $zipName;
$prefix     = "vale-{$version}/";   // top-level dir inside ZIP

// ── Exclusion rules ──────────────────────────────────────────────────────────

/**
 * Return true when a root-relative forward-slash path should be excluded.
 * Checked against both files and directories (directory paths end with /).
 */
function isExcluded(string $rel, bool $includeVendor): bool
{
    // Exact files
    $excludeFiles = [
        '.env',
        '.env.local',
        '.env.production',
        '.env.staging',
        '.env.testing',
        'docker-compose.prod.yml',
        'docker-compose.yml',
        'captain-definition',
        '.dockerignore',
        '.gitignore',
        '.gitleaks',
        '.gitattributes',
        'update-test-manifest.json',
        'phpunit.xml',
        'phpunit.xml.dist',
        '.phpunit.result.cache',
        '.php-cs-fixer.cache',
        '.php-cs-fixer.dist.php',
        'AGENTS.md',
    ];

    // Directory prefixes (root-relative, trailing slash)
    $excludeDirs = [
        'storage/',
        '.git/',
        '.githooks/',
        '.agents/',
        'node_modules/',
        'dist/',
        'coverage/',
        'docker/',
        'tests/',
        '.github/',
        '.idea/',
        '.vscode/',
        '.phpunit.result.cache/',
        'public/update-test/',
        'update-test/',
        'scripts/',
    ];

    if (!$includeVendor) {
        $excludeDirs[] = 'vendor/';
    }

    // Normalise
    $rel = str_replace('\\', '/', $rel);

    foreach ($excludeFiles as $f) {
        if (strtolower($rel) === strtolower($f)) {
            return true;
        }
    }

    foreach ($excludeDirs as $d) {
        $dl = strtolower($d);
        if (strtolower($rel) === rtrim($dl, '/') || str_starts_with(strtolower($rel), $dl)) {
            return true;
        }
    }

    // .env.* variants (but keep .env.example)
    if (preg_match('/^\.env\..+/', $rel) && $rel !== '.env.example') {
        return true;
    }

    // Any other *.env file (_.env, __.env, vale-imported-wp.env, ...) — keep only .env.example
    if (preg_match('/\.env$/', $rel) && strtolower($rel) !== '.env.example') {
        return true;
    }

    // Hidden OS / IDE files
    if (preg_match('/(?:^|\/)\.DS_Store$/', $rel) || preg_match('/(?:^|\/)Thumbs\.db$/i', $rel)) {
        return true;
    }

    return false;
}

// ── Collect files ────────────────────────────────────────────────────────────

info("Vale Release Builder");
info("==========================");
info("Version       : {$version}");
info("Output dir    : {$outputDir}");
info("Compress      : " . ($useCompress ? 'DEFLATE' : 'STORE (no compression)'));
info("Include vendor: " . ($includeVendor ? 'yes' : 'no'));
info("Update deps   : " . ($updateDeps ? 'yes' : 'no'));
info("Signing       : " . ($privateKeyB64 !== '' ? 'Ed25519' : 'none'));
info("Dry run       : " . ($dryRun ? 'YES' : 'no'));
info("Migrations    : " . ($requiresMigration ? 'yes' : 'no') . " ({$migrationNote})");
foreach (['added' => $migrationAdded, 'removed' => $migrationRemoved, 'edited' => $migrationEdited] as $kind => $files) {
    foreach ($files as $migrationFile) {
        info("                - {$migrationFile} [{$kind}]");
    }
}
info('');

if (!$dryRun) {
    if ($updateDeps) {
        info("Running: npm update");
        passthru('npm update 2>&1', $npmUpdateCode);
        if ($npmUpdateCode !== 0) {
            err('npm update failed (exit ' . $npmUpdateCode . ').');
        }
        info('npm update completed.');

        $composerUpdateCmd = 'composer update';
        if ($includeVendor) {
            $composerUpdateCmd .= ' --no-dev --optimize-autoloader';
        }
        info("Running: {$composerUpdateCmd}");
        passthru($composerUpdateCmd . ' 2>&1', $compUpdateCode);
        if ($compUpdateCode !== 0) {
            err('composer update failed (exit ' . $compUpdateCode . ').');
        }
        info('composer update completed.');
        info('');
    }

    // npm ci = locked, reproducible build from package-lock.json (never mutates it)
    info("Running: npm ci");
    passthru('npm ci 2>&1', $npmCode);
    if ($npmCode !== 0) {
        err('npm ci failed (exit ' . $npmCode . ').');
    }
    info('npm ci completed.');

    info("Running: npm run build");
    passthru('npm run build 2>&1', $buildCode);
    if ($buildCode !== 0) {
        err('npm run build failed (exit ' . $buildCode . ').');
    }
    info('npm run build completed.');
    info('');
}

if ($includeVendor && !$dryRun) {
    info("Running: composer install --no-dev --optimize-autoloader");
    passthru('composer install --no-dev --optimize-autoloader 2>&1', $compCode);
    if ($compCode !== 0) {
        err('composer install failed (exit ' . $compCode . ').');
    }
    info('composer install completed.');
    info('');
}

// ── Update version.php BEFORE collecting files so the release version is ─────
// baked into the ZIP.

$versionPhpContent = "<?php\n// Auto-updated by scripts/prepare-release.php during release builds\nreturn '{$version}';\n";
$path              = $rootDir . '/version.php';
if ($dryRun) {
    info("[DRY RUN] Would update: {$path} → '{$version}'");
} else {
    file_put_contents($path, $versionPhpContent);
    info("Version updated: {$path} → '{$version}'");
}
info('');

$readmeFile = $rootDir . '/README.md';
if (file_exists($readmeFile)) {
    $readmeContent = file_get_contents($readmeFile);
    $updatedReadme = preg_replace(
        '/(version-)(\d+\.\d+\.\d+)(-blue)/',
        '${1}' . $version . '${3}',
        $readmeContent
    );
    if ($updatedReadme !== null && $updatedReadme !== $readmeContent) {
        if ($dryRun) {
            info("[DRY RUN] Would update README.md version badge → '{$version}'");
        } else {
            file_put_contents($readmeFile, $updatedReadme);
            info("README.md version badge updated → '{$version}'");
        }
    }
}
info('');

info("Collecting files from: {$rootDir}");

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($rootDir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);

$files = [];
foreach ($iterator as $file) {
    /** @var SplFileInfo $file */
    $abs = str_replace('\\', '/', $file->getRealPath());
    $rel = ltrim(str_replace(str_replace('\\', '/', $rootDir), '', $abs), '/');

    if (isExcluded($rel, $includeVendor)) {
        continue;
    }

    $files[] = ['abs' => $abs, 'rel' => $rel];
}

$fileCount = count($files);
info("Files to include: {$fileCount}");
info('');

// ── Agent assets pack (.agents/ + AGENTS.md) ─────────────────────────────────
// The main ZIP excludes AI-agent tooling; it ships as a separate small release
// asset so developers installing from releases keep the agent skills too.

$agentsDir   = $rootDir . '/.agents';
$agentsFiles = [];

if (is_dir($agentsDir)) {
    $agentsIterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($agentsDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($agentsIterator as $file) {
        /** @var SplFileInfo $file */
        $abs           = str_replace('\\', '/', $file->getRealPath());
        $rel           = ltrim(str_replace(str_replace('\\', '/', $rootDir), '', $abs), '/');
        $agentsFiles[] = ['abs' => $abs, 'rel' => $rel];
    }
}

foreach (['AGENTS.md', 'AGENTS.MD'] as $agentsCandidate) {
    $agentsCandidateAbs = $rootDir . '/' . $agentsCandidate;
    if (file_exists($agentsCandidateAbs)) {
        $agentsFiles[] = ['abs' => $agentsCandidateAbs, 'rel' => $agentsCandidate];
        break;
    }
}

$agentsZipName = "vale-agents-{$version}.zip";
$agentsZipPath = $outputDir . '/' . $agentsZipName;

// Dedicated integration skill asset (.agents/skills/vale-integrate/)
$integrationSkillDir   = $rootDir . '/.agents/skills/vale-integrate';
$integrationSkillFiles = [];

if (is_dir($integrationSkillDir)) {
    $integrationIterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($integrationSkillDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($integrationIterator as $file) {
        /** @var SplFileInfo $file */
        $abs                     = str_replace('\\', '/', $file->getRealPath());
        $rel                     = ltrim(str_replace(str_replace('\\', '/', $rootDir), '', $abs), '/');
        $integrationSkillFiles[] = ['abs' => $abs, 'rel' => $rel];
    }
}

$integrationZipName = "vale-integration-skills-{$version}.zip";
$integrationZipPath = $outputDir . '/' . $integrationZipName;

info("Agent assets      : " . count($agentsFiles) . " file(s)");
info("Integration skill : " . count($integrationSkillFiles) . " file(s)");
info('');

if ($dryRun) {
    info("[DRY RUN] Files that would be included:");
    foreach ($files as $f) {
        echo "  " . $f['rel'] . "\n";
    }
    info('');
    if ($agentsFiles !== []) {
        info("[DRY RUN] Agents pack ({$agentsZipName}) would contain:");
        foreach ($agentsFiles as $f) {
            echo "  " . $f['rel'] . "\n";
        }
        info('');
    }
    if ($integrationSkillFiles !== []) {
        info("[DRY RUN] Integration pack ({$integrationZipName}) would contain:");
        foreach ($integrationSkillFiles as $f) {
            echo "  " . $f['rel'] . "\n";
        }
        info('');
    }
    info("[DRY RUN] No ZIP created.");
    exit(0);
}

// ── Create output directory ──────────────────────────────────────────────────

if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true)) {
    err("Could not create output directory: {$outputDir}");
}

// ── Create ZIP ───────────────────────────────────────────────────────────────

if (file_exists($zipPath)) {
    unlink($zipPath);
}

$zip = new ZipArchive();
$res = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
if ($res !== true) {
    err("ZipArchive::open() failed with code {$res} for: {$zipPath}");
}

$compressionMethod = $useCompress ? ZipArchive::CM_DEFLATE : ZipArchive::CM_STORE;

info("Creating ZIP: {$zipPath}");

foreach ($files as $f) {
    $entryName = $prefix . $f['rel'];
    $zip->addFile($f['abs'], $entryName);
    if ($useCompress) {
        $zip->setCompressionName($entryName, ZipArchive::CM_DEFLATE, 6);
    } else {
        $zip->setCompressionName($entryName, ZipArchive::CM_STORE);
    }
}

$zip->close();

info("ZIP created: {$zipPath}");
info("Files packed: {$fileCount}");
info('');

// ── SHA-256 ──────────────────────────────────────────────────────────────────

$sha256 = hash_file('sha256', $zipPath);
$sizeMb = round(filesize($zipPath) / 1_048_576, 2);

info("SHA-256 : {$sha256}");
info("ZIP size: {$sizeMb} MB");
info('');

// ── Sign (optional) ──────────────────────────────────────────────────────────

$signatureBlock = null;

if ($privateKeyB64 !== '') {
    if (!extension_loaded('sodium')) {
        err('--private-key requires the sodium extension.');
    }
    $secretKey = base64_decode($privateKeyB64, true);
    if ($secretKey === false || strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        err('Invalid Ed25519 private key (expected ' . SODIUM_CRYPTO_SIGN_SECRETKEYBYTES . '-byte base64).');
    }
    $message   = "sha256:{$sha256}";
    $signature = sodium_crypto_sign_detached($message, $secretKey);
    $sigB64    = base64_encode($signature);

    $signatureBlock = [
        'ed25519'           => $sigB64,
        'signature_message' => $message,
    ];

    info("Signed OK (Ed25519)");
    info("Signature: {$sigB64}");
    info('');
}

// ── Generate manifest ────────────────────────────────────────────────────────

$manifest = [
    'version'            => $version,
    'released_at'        => date('Y-m-d'),
    'min_php'            => $minPhp,
    'min_extensions'     => $minExtensions,
    'download_url'       => "https://example.com/releases/{$zipName}",
    'sha256'             => $sha256,
    'requires_migration' => $requiresMigration,
    'changelog'          => $changelogText,
];

if ($signatureBlock !== null) {
    $manifest['signatures']         = ['ed25519' => $signatureBlock['ed25519']];
    $manifest['signature_message']  = $signatureBlock['signature_message'];
}

$manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$manifestPath = $outputDir . '/manifest-' . $version . '.json';
file_put_contents($manifestPath, $manifestJson);

info("Manifest written: {$manifestPath}");
info('');

// ── Write changelog file for CI (release body) ────────────────────────────────

$changelogPath = $outputDir . '/changelog-' . $version . '.md';
file_put_contents($changelogPath, $changelogText);
info("Changelog written: {$changelogPath}");
info('');

// ── Create agent assets pack ZIP ─────────────────────────────────────────────

if ($agentsFiles !== []) {
    if (file_exists($agentsZipPath)) {
        unlink($agentsZipPath);
    }

    $agentsZip = new ZipArchive();
    $res       = $agentsZip->open($agentsZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($res !== true) {
        err("ZipArchive::open() failed with code {$res} for: {$agentsZipPath}");
    }

    info("Creating agents pack: {$agentsZipPath}");

    foreach ($agentsFiles as $f) {
        // Root-relative paths — drop-in overlay for an existing working copy
        $agentsZip->addFile($f['abs'], $f['rel']);
        if ($useCompress) {
            $agentsZip->setCompressionName($f['rel'], ZipArchive::CM_DEFLATE, 6);
        } else {
            $agentsZip->setCompressionName($f['rel'], ZipArchive::CM_STORE);
        }
    }

    $agentsZip->close();

    info("Agents pack created: {$agentsZipPath}");
    info("Agents pack files : " . count($agentsFiles));
    info("Agents pack SHA-256: " . hash_file('sha256', $agentsZipPath));
    info('');
}

// ── Create integration skill pack ZIP ───────────────────────────────────────

if ($integrationSkillFiles !== []) {
    if (file_exists($integrationZipPath)) {
        unlink($integrationZipPath);
    }

    $integrationZip = new ZipArchive();
    $res            = $integrationZip->open(
        $integrationZipPath,
        ZipArchive::CREATE | ZipArchive::OVERWRITE
    );
    if ($res !== true) {
        err("ZipArchive::open() failed with code {$res} for: {$integrationZipPath}");
    }

    info("Creating integration pack: {$integrationZipPath}");

    foreach ($integrationSkillFiles as $f) {
        $integrationZip->addFile($f['abs'], $f['rel']);
        if ($useCompress) {
            $integrationZip->setCompressionName($f['rel'], ZipArchive::CM_DEFLATE, 6);
        } else {
            $integrationZip->setCompressionName($f['rel'], ZipArchive::CM_STORE);
        }
    }

    $integrationZip->close();

    info("Integration pack created: {$integrationZipPath}");
    info("Integration pack files  : " . count($integrationSkillFiles));
    info("Integration pack SHA-256: " . hash_file('sha256', $integrationZipPath));
    info('');
}

info("────────────────────────────────────────────────");
info("MANIFEST (edit download_url and changelog before publishing):");
info("────────────────────────────────────────────────");
echo $manifestJson . "\n";
info("────────────────────────────────────────────────");
info('');
info("Next steps:");
info("  1. Upload {$zipName} to your release server");
if ($agentsFiles !== []) {
    info("     - Also attach {$agentsZipName} as a release asset (AI agent skills pack)");
}
if ($integrationSkillFiles !== []) {
    info("     - Also attach {$integrationZipName} as release asset");
}
info("  2. Set download_url in the manifest to the public download link");
info("  3. Fill in the changelog array");
if ($signatureBlock === null) {
    info("  4. (Optional) Sign with: php scripts/sign-release.php sha256:{$sha256} <private_key_b64>");
}
info("  5. Publish the manifest at your UPDATE_MANIFEST_URL endpoint");
info('');
info("Done.");
exit(0);

// ── Helpers ──────────────────────────────────────────────────────────────────

function info(string $msg): void
{
    echo $msg . "\n";
}

function err(string $msg): never
{
    fwrite(STDERR, "ERROR: {$msg}\n");
    exit(1);
}

/**
 * Compare database/migrations against the previous release tag.
 *
 * Returns null when git cannot answer (no repository, no previous tag, or a
 * shallow checkout without history) — the caller then relies on
 * --requires-migration.
 *
 * The working tree is compared instead of a commit range, so a locally
 * prepared release also sees uncommitted and untracked migration files.
 *
 * @return array{previous_tag: string, added: string[], removed: string[], edited: string[]}|null
 */
function detectMigrationChanges(string $version): ?array
{
    $tags = trim((string) (shell_exec('git tag --sort=-version:refname 2>&1') ?? ''));
    if ($tags === '' || stripos($tags, 'fatal:') !== false) {
        return null;
    }

    $previousTag = null;
    foreach (explode("\n", $tags) as $line) {
        $line = trim($line);
        if (preg_match('/^v?\d+\.\d+\.\d+$/', $line) === 1
            && version_compare(ltrim($line, 'v'), $version, '<')
        ) {
            $previousTag = $line;
            break;
        }
    }

    if ($previousTag === null) {
        return null;
    }

    $tag  = escapeshellarg($previousTag);
    $diff = static fn (string $filter): string => (string) (shell_exec(
        "git diff --name-only --diff-filter={$filter} {$tag} -- database/migrations 2>&1"
    ) ?? '');

    $added     = $diff('A');
    $removed   = $diff('D');
    $edited    = $diff('M');
    $untracked = (string) (shell_exec(
        'git ls-files --others --exclude-standard -- database/migrations 2>&1'
    ) ?? '');

    if (stripos($added . $removed . $edited . $untracked, 'fatal:') !== false) {
        return null;
    }

    $collect = static function (string $output): array {
        $files = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if (preg_match('#^database/migrations/.+\.php$#', $line) === 1) {
                $files[$line] = true;
            }
        }

        return array_keys($files);
    };

    return [
        'previous_tag' => $previousTag,
        'added'        => $collect($added . "\n" . $untracked),
        'removed'      => $collect($removed),
        'edited'       => $collect($edited),
    ];
}

/**
 * Extract the changelog section for a specific version from CHANGELOG.md.
 * Expects Keep-a-Changelog format: ## [x.y.z] ... heading lines.
 * Throws (via err()) if the version section is not found.
 */
/**
 * Extract the changelog section for a specific version from CHANGELOG.md.
 * Expects Keep-a-Changelog format: ## [x.y.z] - <date|UNRELEASED>
 * If the heading contains UNRELEASED, it is replaced with today's date
 * and CHANGELOG.md is updated in-place.
 * Throws (via err()) if the version section is not found.
 */
function parseChangelog(string $file, string $version): string
{
    $raw     = file_get_contents($file);
    $lines   = explode("\n", str_replace("\r\n", "\n", $raw));
    $inBlock = false;
    $block   = [];
    $today   = date('Y-m-d');
    $updated = false;

    foreach ($lines as &$line) {
        if (preg_match('/^## \[' . preg_quote($version, '/') . '\]\s*-\s*UNRELEASED/i', $line)) {
            $line    = "## [{$version}] - {$today}";
            $updated = true;
            $inBlock = true;
            $block[] = $line;
            continue;
        }

        if (preg_match('/^## \[' . preg_quote($version, '/') . '\]/i', $line)) {
            $inBlock = true;
            $block[] = $line;
            continue;
        }

        if ($inBlock) {
            if (preg_match('/^## \[/', $line)) {
                break;
            }
            $block[] = $line;
        }
    }
    unset($line);

    if (empty($block)) {
        err(
            "No CHANGELOG.md entry found for version [{$version}].\n" .
            "       Add a section like:\n\n" .
            "         ## [{$version}] - UNRELEASED\n\n" .
            "         ### Added\n" .
            "         - ...\n"
        );
    }

    if ($updated) {
        file_put_contents($file, implode("\n", $lines));
        info("CHANGELOG.md updated: UNRELEASED → {$today}");
    }

    return trim(implode("\n", $block));
}
