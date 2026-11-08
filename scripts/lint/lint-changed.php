<?php

/**
 * Runs syntax and lint checks only on changed PHP and Twig files.
 */

$repoRoot = dirname(__DIR__, 2);
chdir($repoRoot);

function runGitFileList($command, array $extensions = ['.php', '.twig', '.js'])
{
    $devNull = DIRECTORY_SEPARATOR === '\\' ? '2>NUL' : '2>/dev/null';
    exec($command . ' ' . $devNull, $output);

    return array_values(array_filter($output, function ($file) use ($extensions) {
        foreach ($extensions as $ext) {
            if (str_ends_with($file, $ext)) {
                return true;
            }
        }
        return false;
    }));
}

function getChangedFiles($base, $target, array $extensions = ['.php', '.twig', '.js'])
{
    if (!$base) {
        return [];
    }

    $diffRange = "$base...$target";
    echo "Comparing committed changes: $diffRange\n";

    $cmd = "git diff --name-only --diff-filter=ACMRTUXB " . escapeshellarg($diffRange);
    return runGitFileList($cmd, $extensions);
}

function getWorkingTreeFiles(array $extensions = ['.php', '.twig', '.js'])
{
    echo "Including working tree changes (staged, unstaged, untracked)\n";

    $tracked   = runGitFileList('git diff --name-only --diff-filter=ACMRTUXB HEAD', $extensions);
    $untracked = runGitFileList('git ls-files --others --exclude-standard', $extensions);

    return array_values(array_unique(array_merge($tracked, $untracked)));
}

/**
 * Line numbers (1-based, working-tree file) added by the change under review.
 *
 * Whole-file PHPCS scans flag pre-existing violations in legacy files, so the
 * gate only fails on violations sitting on added lines. Line numbers refer to
 * the working-tree file that PHPCS actually checks.
 *
 * @return array<int, true> set of added line numbers
 */
function getAddedLines(string $file, string $base, string $target, bool $includeWorktree): array
{
    $added   = [];
    $devNull = DIRECTORY_SEPARATOR === '\\' ? '2>NUL' : '2>/dev/null';

    $collect = static function (string $command) use (&$added, $devNull): void {
        exec($command . ' ' . $devNull, $output);
        $new = 0;
        foreach ($output as $line) {
            if (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/', $line, $m)) {
                $new = (int) $m[1];
                continue;
            }
            if (str_starts_with($line, '+') && !str_starts_with($line, '+++')) {
                $added[$new] = true;
                ++$new;
            } elseif (str_starts_with($line, '-') && !str_starts_with($line, '---')) {
                // Removed line: new-file counter stays put.
            } else {
                ++$new;
            }
        }
    };

    if ($base !== '') {
        $diffRange = "$base...$target";
        $collect('git diff -U0 ' . escapeshellarg($diffRange) . ' -- ' . escapeshellarg($file));
    }
    if ($includeWorktree) {
        $lsFiles = 'git ls-files --others --exclude-standard -- ' . escapeshellarg($file);
        exec($lsFiles . ' ' . $devNull, $isUntracked);
        if ($isUntracked !== []) {
            $count = count(file($file));
            for ($i = 1; $i <= $count; ++$i) {
                $added[$i] = true;
            }
        } else {
            $collect('git diff -U0 HEAD -- ' . escapeshellarg($file));
        }
    }

    return $added;
}

if (!is_dir('.git')) {
    fwrite(STDERR, "Git repository not found. Run this script from the repository root.\n");
    exit(1);
}

$githubEventName = getenv('GITHUB_EVENT_NAME');
$githubActions   = getenv('GITHUB_ACTIONS') === 'true';

$baseRef         = '';
$after           = 'HEAD';
$includeWorktree = false;

if ($githubActions && $githubEventName) {
    echo "Running inside GitHub Actions event: $githubEventName\n";

    if ($githubEventName === 'pull_request') {
        $baseRef = "origin/" . getenv('GITHUB_BASE_REF');
        $after   = getenv('GITHUB_SHA');
    } else {
        $baseRef = getenv('GITHUB_EVENT_BEFORE');
        $after   = getenv('GITHUB_SHA');
    }
} else {
    // Parse manual arguments or use defaults
    $args      = getopt('', ['base:', 'after:', 'skip-fetch']);
    $baseRef   = $args['base']  ?? '';
    $after     = $args['after'] ?? 'HEAD';
    $skipFetch = isset($args['skip-fetch']);

    if (!$baseRef) {
        foreach (['origin/main', 'main', 'origin/master', 'master'] as $candidate) {
            $ref = str_starts_with($candidate, 'origin/') ? 'refs/remotes/' . $candidate : 'refs/heads/' . $candidate;
            if (exec("git show-ref --verify --quiet $ref && echo 1") === '1') {
                $baseRef = $candidate;
                break;
            }
        }
        $baseRef = $baseRef ?: 'HEAD~1';
    }

    // Local runs also lint uncommitted working tree changes.
    $includeWorktree = !isset($args['base']);
}

if (str_starts_with($baseRef, 'origin/') && !isset($skipFetch)) {
    echo "Fetching remote ref $baseRef\n";
    $remoteBranch = str_replace('origin/', '', $baseRef);
    passthru("git fetch --no-tags --prune --depth=1 origin " . escapeshellarg($remoteBranch));
}

$changedFiles = getChangedFiles($baseRef, $after);

if ($includeWorktree) {
    $changedFiles = array_merge($changedFiles, getWorkingTreeFiles());
    $changedFiles = array_values(array_unique($changedFiles));
}

if (empty($changedFiles)) {
    echo "No changed PHP or Twig files found. Nothing to lint.\n";
    exit(0);
}

$changedFiles = array_unique($changedFiles);
sort($changedFiles);
echo "Changed files:\n" . implode("\n", $changedFiles) . "\n\n";

$resolvedFiles = [];
foreach ($changedFiles as $file) {
    $realPath = realpath($file);
    if ($realPath && file_exists($realPath)) {
        $resolvedFiles[] = $realPath;
    } else {
        echo "Warning: File not found on disk, skipping: $file\n";
    }
}

if (empty($resolvedFiles)) {
    fwrite(STDERR, "No valid changed files were found after path resolution.\n");
    exit(1);
}

// Split files by type
$phpFiles  = array_filter($resolvedFiles, fn ($f) => str_ends_with($f, '.php'));
$twigFiles = array_filter($resolvedFiles, fn ($f) => str_ends_with($f, '.twig'));
$phpFiles  = array_values($phpFiles);
$twigFiles = array_values($twigFiles);
$jsFiles   = array_values(array_filter($resolvedFiles, fn ($f) => str_ends_with($f, '.js')));

$lintResults = [
    "PHP Syntax"   => true,
    "PHP-CS-Fixer" => true,
    "PHPCS"        => true,
    "Twig Lint"    => true,
    "JS Syntax"    => true,
];

$vendorBin = $repoRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR;

if (!empty($phpFiles)) {
    foreach ($phpFiles as $file) {
        echo "Checking PHP syntax: $file\n";
        passthru("php -l " . escapeshellarg($file), $exitCode);
        if ($exitCode !== 0) {
            $lintResults["PHP Syntax"] = false;
        }
    }

    $csfixerExists = file_exists($vendorBin . 'php-cs-fixer')
        || file_exists($vendorBin . 'php-cs-fixer.bat');
    if (!$csfixerExists) {
        fwrite(
            STDERR,
            "Vendor binaries not installed. Run composer install first.\n"
        );
        exit(1);
    }

    echo "Running php-cs-fixer on changed PHP files.\n";
    $phpFileArgs = array_map(function ($f) use ($repoRoot) {
        $rel = ltrim(str_replace('\\', '/', substr($f, strlen($repoRoot))), '/');
        return $rel;
    }, $phpFiles);
    $fileArgs    = implode(' ', array_map('escapeshellarg', $phpFileArgs));
    passthru("composer lint -- $fileArgs", $exitCode);
    if ($exitCode !== 0) {
        $lintResults["PHP-CS-Fixer"] = false;
    }

    echo "Running phpcs on changed lines only.\n";
    echo "(Whole-file scans would flag pre-existing violations in legacy files.)\n";
    $phpcsBin = $vendorBin . 'phpcs';
    if (DIRECTORY_SEPARATOR === '\\' && file_exists($vendorBin . 'phpcs.bat')) {
        $phpcsBin = $vendorBin . 'phpcs.bat';
    }
    $phpcsFailed = false;
    foreach ($phpFiles as $file) {
        $rel   = ltrim(str_replace('\\', '/', substr($file, strlen($repoRoot))), '/');
        $added = getAddedLines($rel, $baseRef, $after, $includeWorktree);
        if ($added === []) {
            continue;
        }
        $report = shell_exec(
            escapeshellarg($phpcsBin)
            . ' --standard=' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . '.phpcs.xml.dist')
            . ' --report=json -- ' . escapeshellarg($file)
        );
        $data = json_decode((string) $report, true);
        $hits = [];
        foreach (($data['files'] ?? []) as $info) {
            foreach (($info['messages'] ?? []) as $message) {
                if (isset($added[(int) $message['line']])) {
                    $hits[] = $message;
                }
            }
        }
        if ($hits === []) {
            continue;
        }
        $phpcsFailed = true;
        echo "\nFILE: $file\n";
        echo str_repeat('-', 80) . "\n";
        foreach ($hits as $hit) {
            echo " {$hit['line']} | {$hit['type']} | {$hit['message']}\n";
        }
    }
    if ($phpcsFailed) {
        $lintResults["PHPCS"] = false;
    }
} else {
    echo "No changed PHP files to lint.\n";
}

if (!empty($twigFiles)) {
    echo "Running Twig lint on changed Twig files.\n";
    $twigArgs = implode(' ', array_map('escapeshellarg', $twigFiles));
    $twigLint = escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . 'twig-lint.php');
    passthru("php $twigLint $twigArgs", $exitCode);
    if ($exitCode !== 0) {
        $lintResults["Twig Lint"] = false;
    }
} else {
    echo "No changed Twig files to lint.\n";
}

if (!empty($twigFiles) || !empty($jsFiles)) {
    echo "Running JS syntax check on changed files.\n";
    $jsArgs = array_merge($twigFiles, $jsFiles);
    $jsArgs = implode(' ', array_map('escapeshellarg', $jsArgs));
    $jsLint = escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . 'js-check.php');
    passthru("php $jsLint $jsArgs", $exitCode);
    if ($exitCode !== 0) {
        $lintResults["JS Syntax"] = false;
    }
} else {
    echo "No changed JS or Twig files to check.\n";
}

echo "\n--- Lint Summary ---\n";
$anyFailed = false;
foreach (["PHP Syntax", "PHP-CS-Fixer", "PHPCS", "Twig Lint", "JS Syntax"] as $key) {
    if ($lintResults[$key]) {
        echo "[V] $key: Passed\n";
    } else {
        echo "[X] $key: Failed\n";
        $anyFailed = true;
    }
}

if ($anyFailed) {
    echo "\nLint checks failed. Please fix the issues above.\n";
    exit(1);
} else {
    echo "\nAll lint checks passed!\n";
    exit(0);
}
