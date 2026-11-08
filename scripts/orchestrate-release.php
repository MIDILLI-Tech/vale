#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * scripts/orchestrate-release.php
 *
 * Release orchestration: auto-generate changelog from conventional commits,
 * create git tag, update CHANGELOG.md, and optionally build the release ZIP.
 *
 * Usage:
 *   php scripts/orchestrate-release.php --version=0.10.4 --tag --changelog --build
 *
 * Flags:
 *   --version=x.y.z         Release version (required unless version.php exists)
 *   --tag                   Create git tag vX.Y.Z and push it
 *   --changelog             Generate changelog from conventional commits since last tag
 *   --build                 Run prepare-release.php to build the ZIP
 *   --description="text"    Custom release notes inserted above the commit list
 *   --force                 Overwrite existing version section in CHANGELOG.md
 *   --dry-run               Show plan without making changes
 *   --include-vendor        (passed to prepare-release.php)
 *   --compress              (passed to prepare-release.php)
 *   --requires-migration    (passed to prepare-release.php)
 *   --no-requires-migration (passed to prepare-release.php)
 */

$rootDir = dirname(__DIR__);
chdir($rootDir);

// ── Option parsing ────────────────────────────────────────────────────────────

$opts = getopt('', [
    'version:',
    'tag',
    'changelog',
    'build',
    'description:',
    'force',
    'dry-run',
    'include-vendor',
    'compress',
    'requires-migration',
    'no-requires-migration',
]);

$dryRun        = isset($opts['dry-run']);
$doTag         = isset($opts['tag']);
$doChangelog   = isset($opts['changelog']);
$doBuild       = isset($opts['build']);
$force         = isset($opts['force']);
$description   = isset($opts['description']) ? (string) $opts['description'] : '';
$includeVendor = isset($opts['include-vendor']);
$useCompress   = isset($opts['compress']);

if (!$doChangelog && !$doTag && !$doBuild) {
    err("At least one of --tag, --changelog, or --build must be specified.");
}

// ── Resolve version ───────────────────────────────────────────────────────────

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

$tagName = 'v' . $version;
$today   = date('Y-m-d');

info("Vale Release Orchestrator");
info("==========================");
info("Version     : {$version}");
info("Tag         : {$tagName}");
info("Changelog   : " . ($doChangelog ? 'yes' : 'no'));
info("Create tag  : " . ($doTag ? 'yes' : 'no'));
info("Build       : " . ($doBuild ? 'yes' : 'no'));
info("Force       : " . ($force ? 'yes' : 'no'));
info("Description : " . ($description !== '' ? mb_substr($description, 0, 60) . (mb_strlen($description) > 60 ? '...' : '') : 'no'));
info("Dry run     : " . ($dryRun ? 'YES' : 'no'));
info('');

// ── Load .env for prepare-release.php ────────────────────────────────────────

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

// ── Step 1: Generate changelog ────────────────────────────────────────────────

$changelogMd = '';

if ($doChangelog) {
    info("[1/4] Generating changelog from conventional commits...");

    $changelogMd = generateChangelog($rootDir, $version, $tagName, $description, $force);

    if ($dryRun) {
        info("[DRY RUN] Generated changelog section:");
        info('');
        echo $changelogMd . "\n";
        info('');
    } else {
        $changelogPath = $rootDir . '/CHANGELOG.md';
        if (!file_exists($changelogPath)) {
            err('CHANGELOG.md not found.');
        }

        $existing   = file_get_contents($changelogPath);
        $newHeading = "## [{$version}] - {$today}";

        if (str_contains($existing, $newHeading)) {
            if ($force) {
                info("Overwriting existing section for [{$version}].");
                $merged   = mergeChangelogSections($existing, $changelogMd, $version);
                $existing = preg_replace(
                    '/^## \[' . preg_quote($version, '/') . '\].*?(?=^## \[|\z)/ms',
                    rtrim($merged, "\n") . "\n\n",
                    $existing
                );
                file_put_contents($changelogPath, $existing);
                info("CHANGELOG.md updated (overwritten, manual entries preserved).");
            } else {
                info("Section [{$version}] exists — merging new entries.");
                $merged   = mergeChangelogSections($existing, $changelogMd, $version);
                $existing = preg_replace(
                    '/^## \[' . preg_quote($version, '/') . '\].*?(?=^## \[|\z)/ms',
                    rtrim($merged, "\n") . "\n\n",
                    $existing
                );
                file_put_contents($changelogPath, $existing);
                info("CHANGELOG.md updated (merged).");
            }
        } else {
            $header = '';
            $rest   = $existing;
            if (preg_match('/^(.*?)(?=^## \[)/ms', $existing, $headerMatch)) {
                $header = rtrim($headerMatch[1]) . "\n\n";
                $rest   = preg_replace('/^(.*?)(?=^## \[)/ms', '', $existing);
            }
            file_put_contents($changelogPath, $header . $changelogMd . "\n\n" . $rest);
            info("CHANGELOG.md updated: new section [{$version}] added.");
        }
    }
}

// ── Step 2: Git tag ───────────────────────────────────────────────────────────

if ($doTag) {
    info("[2/4] Creating git tag...");

    if ($dryRun) {
        info("[DRY RUN] Would create tag: {$tagName}");
        info("[DRY RUN] Would push tag to remote.");
    } else {
        // Check if tag already exists
        exec("git tag -l " . escapeshellarg($tagName) . " 2>&1", $tagList, $tagListCode);
        if ($tagListCode !== 0) {
            err('Could not list git tags: ' . trim(implode("\n", $tagList)));
        }
        $existingTag = trim(implode("\n", $tagList));

        if ($existingTag !== '') {
            if ($force) {
                info("Deleting existing tag: {$tagName}");
                exec("git tag -d " . escapeshellarg($tagName) . " 2>&1", $deleteOut, $deleteCode);
                if ($deleteCode !== 0) {
                    err('Could not delete local tag: ' . trim(implode("\n", $deleteOut)));
                }
                exec("git push origin :refs/tags/" . escapeshellarg($tagName) . " 2>&1", $deleteRemoteOut, $deleteRemoteCode);
                if ($deleteRemoteCode !== 0) {
                    err('Could not delete remote tag: ' . trim(implode("\n", $deleteRemoteOut)));
                }
            } else {
                err("Tag {$tagName} already exists. Use --force to recreate.");
            }
        }

        // Stage CHANGELOG.md if it was modified
        $changelogPath = $rootDir . '/CHANGELOG.md';
        if (file_exists($changelogPath) && $doChangelog) {
            exec("git add " . escapeshellarg('CHANGELOG.md') . " 2>&1", $addOut, $addCode);
            if ($addCode !== 0) {
                err('Could not stage CHANGELOG.md: ' . trim(implode("\n", $addOut)));
            }
            $commitMsg = "chore: release {$version}";
            exec("git commit -m " . escapeshellarg($commitMsg) . " 2>&1", $commitOut, $commitCode);
            if ($commitCode === 0) {
                info("Committed: {$commitMsg}");
            } else {
                info('No changelog changes to commit.');
            }
        }

        // Create annotated tag
        $tagMsg = "Release {$version}\n\n" .
            ($description !== '' ? $description . "\n\n" : '') .
            "Generated by orchestrate-release.php\n" .
            "Date: " . date('Y-m-d H:i:s');

        exec("git tag -a " . escapeshellarg($tagName) . " -m " . escapeshellarg($tagMsg) . " 2>&1", $tagOut, $tagCode);
        if ($tagCode !== 0) {
            err('Could not create tag: ' . trim(implode("\n", $tagOut)));
        }
        info("Tag created: {$tagName}");

        // Push
        exec("git push origin " . escapeshellarg($tagName) . " 2>&1", $pushOut, $pushCode);
        if ($pushCode !== 0) {
            err('Could not push tag: ' . trim(implode("\n", $pushOut)));
        }
        info('Tag pushed: ' . trim(implode("\n", $pushOut)));
    }
}

// ── Step 3: Build release ─────────────────────────────────────────────────────

if ($doBuild) {
    info("[3/4] Building release package...");

    $prepareScript = $rootDir . '/scripts/prepare-release.php';
    if (!file_exists($prepareScript)) {
        err('prepare-release.php not found at: ' . $prepareScript);
    }

    $cmd = "php " . escapeshellarg($prepareScript) .
        " --version=" . escapeshellarg($version) .
        " --include-vendor" .
        ($useCompress ? " --compress" : "") .
        (isset($opts['requires-migration']) ? " --requires-migration" : "") .
        (isset($opts['no-requires-migration']) ? " --no-requires-migration" : "");

    if ($dryRun) {
        info("[DRY RUN] Would run: {$cmd}");
    } else {
        passthru($cmd . " 2>&1", $buildCode);
        if ($buildCode !== 0) {
            err("prepare-release.php failed (exit {$buildCode}).");
        }
        info("Release package built successfully.");
    }
}

// ── Done ──────────────────────────────────────────────────────────────────────

info('');
info("[4/4] Done.");
info("Version: {$version}");
if ($doTag) {
    info("Tag:    {$tagName}");
}
if ($doChangelog) {
    info("Changelog generated and written to CHANGELOG.md");
}
if ($doBuild) {
    info("Release package: dist/vale-{$version}.zip");
}

exit(0);

// ── Helpers ───────────────────────────────────────────────────────────────────

function info(string $msg): void
{
    echo $msg . "\n";
}

function err(string $msg): never
{
    fwrite(STDERR, "ERROR: {$msg}\n");
    exit(1);
}

function generateChangelog(
    string $rootDir,
    string $version,
    string $tagName,
    string $description,
    bool $force
): string {
    $changelogPath = $rootDir . '/CHANGELOG.md';

    // Find the last tag.
    // NOTE: shell_exec() returns null when the command produces no output
    // (e.g. no tags exist, or shallow checkout without tags), so every call
    // must be null-safe — trim(null) is a TypeError on PHP 8+.
    // Commands are kept portable (no 2>NUL / findstr / more — those are
    // Windows-only and break on Linux CI runners).
    $lastTag = trim((string) (shell_exec('git describe --tags --abbrev=0 2>&1') ?? ''));
    if ($lastTag === '' || str_starts_with($lastTag, 'fatal:') || str_contains($lastTag, 'No names found')) {
        $lastTag = '';
    }
    if ($lastTag === '') {
        $tagList = (string) (shell_exec('git tag --sort=-version:refname 2>&1') ?? '');
        foreach (explode("\n", $tagList) as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, 'fatal:')) {
                $lastTag = $line;
                break;
            }
        }
    }

    if ($lastTag === '') {
        info("  No previous tags found — using all commits.");
        $logRange = '';
    } else {
        $logRange = escapeshellarg($lastTag) . '..HEAD';
    }

    // Get commit log
    $logOutput = shell_exec(
        "git log --format=%B --no-merges " . $logRange . " 2>&1"
    );

    if ($logOutput === null || trim($logOutput) === '') {
        info("  No commits found since last tag.");
        $commits = [];
    } else {
        $commits = parseCommits($logOutput);
    }

    if (empty($commits)) {
        info("  No conventional commits found.");
    }

    // Group commits by section
    $sections = [
        'Added'          => [],
        'Fixed'          => [],
        'Security'       => [],
        'Performance'    => [],
        'Changed'        => [],
        'Documentation'  => [],
        'Testing'        => [],
        'Deprecated'     => [],
        'Removed'        => [],
    ];

    $seen = [];

    foreach ($commits as $commit) {
        $prefix  = $commit['prefix'];
        $message = $commit['message'];

        if (isset($seen[$message])) {
            continue;
        }
        $seen[$message] = true;

        $bullet = '- ' . $message;

        switch ($prefix) {
            case 'feat':
            case 'add':
                $sections['Added'][] = $bullet;
                break;
            case 'fix':
                $sections['Fixed'][] = $bullet;
                break;
            case 'security':
                $sections['Security'][] = $bullet;
                break;
            case 'perf':
                $sections['Performance'][] = $bullet;
                break;
            case 'refactor':
            case 'chore':
                $sections['Changed'][] = $bullet;
                break;
            case 'docs':
                $sections['Documentation'][] = $bullet;
                break;
            case 'test':
                $sections['Testing'][] = $bullet;
                break;
            case 'deprecate':
                $sections['Deprecated'][] = $bullet;
                break;
            case 'remove':
                $sections['Removed'][] = $bullet;
                break;
        }
    }

    // Build markdown
    $lines   = [];
    $lines[] = "## [{$version}] - " . date('Y-m-d');
    $lines[] = '';

    // Description (custom release notes) goes first
    if ($description !== '') {
        $lines[] = $description;
        $lines[] = '';
    }

    $hasContent = false;

    foreach ($sections as $sectionName => $items) {
        if (empty($items)) {
            continue;
        }
        $hasContent = true;
        $lines[]    = '### ' . $sectionName;
        foreach ($items as $item) {
            $lines[] = $item;
        }
        $lines[] = '';
    }

    if (!$hasContent) {
        $lines[] = '### Added';
        $lines[] = '- Initial release';
        $lines[] = '';
    }

    return rtrim(implode("\n", $lines), "\n");
}

function parseCommits(string $logOutput): array
{
    $commits = [];
    $entries = preg_split('/^(?=\S)/m', $logOutput, -1, PREG_SPLIT_NO_EMPTY);

    if ($entries === false) {
        return $commits;
    }

    foreach ($entries as $entry) {
        $entry = trim($entry);
        if ($entry === '') {
            continue;
        }

        $firstLine = explode("\n", $entry)[0];
        $firstLine = trim($firstLine);

        // Skip merge commits
        if (preg_match('/^Merge/i', $firstLine)) {
            continue;
        }

        // Parse conventional commit: type(scope)?: description
        if (preg_match('/^(fix|feat|add|chore|refactor|docs|test|perf|security|deprecate|remove)(?:\([^)]+\))?:\s*(.+)$/i', $firstLine, $m)) {
            $message = trim($m[2]);
            $message = preg_replace('/\s*Signed-off-by:.*$/m', '', $message);
            $message = trim($message);
            if ($message !== '') {
                $commits[] = [
                    'prefix'   => strtolower($m[1]),
                    'message'  => $message,
                ];
            }
        }
    }

    return $commits;
}

/**
 * Normalize a changelog line for deduplication comparison.
 * Strips leading/trailing whitespace and collapses internal spaces.
 */
function normalizeLine(string $line): string
{
    $trimmed = trim($line);
    $trimmed = preg_replace('/\s+/', ' ', $trimmed);
    return $trimmed;
}

/**
 * Merge new auto-generated changelog section with an existing one,
 * preserving manual entries (comments, notes) that the user added.
 */
function mergeChangelogSections(string $existingContent, string $newSection, string $version): string
{
    $today   = date('Y-m-d');
    $heading = "## [{$version}] - {$today}";

    // Extract existing section content
    if (!preg_match('/^## \[' . preg_quote($version, '/') . '\].*?(?=^## \[|\z)/ms', $existingContent, $existingMatch)) {
        return $heading . "\n" . ltrim($newSection, "\n");
    }
    $existingSection = $existingMatch[0];

    // Remove the heading line from existing section to get body
    $existingBody = preg_replace('/^## \[' . preg_quote($version, '/') . '\].*?\n/', '', $existingSection, 1);
    $existingBody = rtrim($existingBody);

    // Separate manual entries from auto-generated ones in existing section
    $manualEntries = [];
    $autoLines     = [];
    $inAutoSection = false;

    foreach (explode("\n", $existingBody) as $line) {
        if (preg_match('/^### /', $line)) {
            $inAutoSection = true;
            $autoLines[]   = $line;
        } elseif ($inAutoSection && preg_match('/^- /', $line)) {
            $autoLines[] = $line;
        } elseif (trim($line) === '') {
            $autoLines[] = $line;
        } else {
            $inAutoSection   = false;
            $manualEntries[] = $line;
        }
    }

    // Parse new section: extract description (text before first ###) and sections
    $newDescription     = '';
    $newSections        = [];
    $currentSection     = null;
    $beforeFirstSection = true;

    foreach (explode("\n", $newSection) as $line) {
        if (preg_match('/^## /', $line)) {
            continue;
        }
        if (preg_match('/^### /', $line)) {
            $beforeFirstSection           = false;
            $currentSection               = trim($line);
            $newSections[$currentSection] = [];
        } elseif ($currentSection !== null && preg_match('/^- /', $line)) {
            $newSections[$currentSection][] = $line;
        } elseif ($beforeFirstSection && trim($line) !== '') {
            $newDescription .= ($newDescription !== '' ? "\n" : '') . $line;
        }
    }

    // Build merged output
    $mergedLines = [];
    $seen        = [];

    // Heading
    $mergedLines[] = $heading;
    $mergedLines[] = '';

    // Description from new section
    if ($newDescription !== '') {
        $mergedLines[] = $newDescription;
        $mergedLines[] = '';
    }

    // Add new sections first
    foreach ($newSections as $sectionName => $items) {
        $mergedLines[] = $sectionName;
        foreach ($items as $item) {
            $mergedLines[]              = $item;
            $seen[normalizeLine($item)] = true;
        }
        $mergedLines[] = '';
    }

    // Add existing auto entries that aren't duplicates
    foreach ($autoLines as $line) {
        if (trim($line) === '') {
            if (count($mergedLines) > 0 && $mergedLines[count($mergedLines) - 1] !== '') {
                $mergedLines[] = '';
            }
        } elseif (isset($seen[normalizeLine($line)])) {
            continue;
        } else {
            $mergedLines[] = $line;
            if (preg_match('/^- /', $line)) {
                $seen[normalizeLine($line)] = true;
            }
        }
    }

    // Append manual entries at the end
    if (!empty($manualEntries)) {
        $mergedLines[] = '';
        $mergedLines[] = '<!-- manual entries below -->';
        $mergedLines   = array_merge($mergedLines, $manualEntries);
    }

    return implode("\n", $mergedLines);
}
