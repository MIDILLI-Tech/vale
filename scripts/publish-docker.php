#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * scripts/publish-docker.php
 *
 * Build and publish the four Vale Docker images to a registry.
 *
 * Tag scheme (registry = midillitech/vale, Docker Hub):
 *   immutable Apache : latest, apache, <ver>, <maj.min>,
 *                      <maj.min>-apache, <ver>-apache
 *   immutable Nginx  : nginx, <maj.min>-nginx, <ver>-nginx
 *   mutable Apache   : mutable-apache
 *   mutable Nginx    : mutable-nginx
 *   edge builds      : edge, edge-apache, edge-nginx  (no version/latest tags)
 *
 * Note: the bare "<maj>" tag (and its "-apache"/"-nginx" variants) is only
 * published once the version is >= 1.0.0 — for a 0.x release it would move on
 * every minor release and only duplicate "latest".
 *
 * The default (recommended) variant is Apache, so it carries the unsuffixed
 * tags and Nginx always carries a -nginx suffix. Pinned tags are pushed
 * before floating tags, so a floating tag never points at an unverified image.
 *
 * Usage:
 *   php scripts/publish-docker.php [options]
 *
 * Options:
 *   --version=<x.y.z>     Release version (default: exact git tag, then version.php)
 *   --registry=<name>     Image name                  (default: midillitech/vale)
 *   --flavor=<f>          all | immutable | mutable   (default: all)
 *   --variants=<list>     apache,nginx                (default: both)
 *   --platforms=<list>    Build platforms             (default: linux/amd64)
 *   --only=<target>       immutable-apache | immutable-nginx | mutable-apache | mutable-nginx
 *   --sha-tags            Also tag each image with the short commit SHA
 *   --floating-only       Rebuild mode: only move floating tags, leave pinned
 *                        release tags untouched (never overwrite a published
 *                        release artifact). Used by the weekly security rebuild
 *   --edge                Force edge tags even when a release version is known
 *   --push                Push to the registry        (default: build only)
 *   --smoke               Start each image and verify /api/v1/health?deep=1
 *   --port=<n>            Fixed host port for --smoke (default: random free port)
 *   --keep                Keep the smoke container running after a failure
 *   --no-cache            Build without the Docker layer cache
 *   --dry-run             Print the resolved plan and commands, run nothing
 *   --help                Show this help
 *
 * A resolved release version produces the full tag set (pinned tags plus
 * latest/apache/nginx). Only when no version can be determined at all does the
 * script fall back to edge mode, which moves the edge* tags and leaves latest
 * and every version tag untouched.
 *
 * Exit codes:
 *   0  success
 *   1  error (printed to STDERR)
 */

$rootDir = dirname(__DIR__);
chdir($rootDir);

$opts = getopt('', [
    'version:',
    'registry:',
    'flavor:',
    'variants:',
    'platforms:',
    'only:',
    'port:',
    'push',
    'smoke',
    'sha-tags',
    'floating-only',
    'edge',
    'no-cache',
    'keep',
    'dry-run',
    'help',
]);

if (isset($opts['help'])) {
    echo "Vale Docker Publisher\n=====================\n\n";
    echo usageBlock(__FILE__);
    exit(0);
}

$dryRun        = isset($opts['dry-run']);
$doPush        = isset($opts['push']);
$doSmoke       = isset($opts['smoke']);
$keepContainer = isset($opts['keep']);
$noCache       = isset($opts['no-cache']);
$forceEdge     = isset($opts['edge']);

// ── Resolve inputs ───────────────────────────────────────────────────────────

$allTargets = targets();

$selected = selectTargets($allTargets, $opts);
if ($selected === []) {
    err('No build targets matched --flavor/--variants/--only.');
}

$platforms = splitList(isset($opts['platforms']) ? (string) $opts['platforms'] : 'linux/amd64');
if ($platforms === []) {
    err('--platforms must not be empty.');
}
$multiPlatform = count($platforms) > 1;

if ($multiPlatform && $doSmoke) {
    err('--smoke cannot be combined with a multi-platform build '
        . '(a multi-platform result cannot be loaded into the local engine).');
}
if ($multiPlatform && !$doPush) {
    err('A multi-platform build requires --push — buildx cannot --load it locally.');
}

[$version, $versionSource, $edgeMode] = resolveVersion($opts, $rootDir, $forceEdge);

$registry = normalizeRegistry(
    isset($opts['registry']) ? (string) $opts['registry'] : 'midillitech/vale'
);

$meta = projectMetadata($rootDir);
$sha  = shortSha();

if (isset($opts['port']) && (int) $opts['port'] > 0) {
    $fixedPort = (int) $opts['port'];
} else {
    $fixedPort = 0;
}

// ── Build the plan ───────────────────────────────────────────────────────────

$shaTags      = isset($opts['sha-tags']);
$floatingOnly = isset($opts['floating-only']);
$plan         = [];

foreach ($selected as $name => $target) {
    [$pinned, $floating] = computeTags($name, $version, $sha, $edgeMode, $shaTags && !$floatingOnly);
    if ($floatingOnly) {
        $pinned = [];
    }
    $plan[$name] = [
        'name'     => $name,
        'target'   => $target,
        'pinned'   => $pinned,
        'floating' => $floating,
        'tags'     => array_values(array_unique(array_merge($pinned, $floating))),
    ];
}

// ── Header ───────────────────────────────────────────────────────────────────

out('Vale Docker Publisher');
out('=====================');
out('');
out('Registry     : ' . $registry);
out('Version      : ' . $version . ' (' . $versionSource . ')');
out('Mode         : ' . ($edgeMode ? 'edge (no version / latest tags)' : 'release'));
out('Revision     : ' . $sha);
out('Platforms    : ' . implode(', ', $platforms));
out('Push         : ' . ($doPush ? 'yes' : 'no (build only)'));
out('Smoke test   : ' . ($doSmoke ? 'yes' : 'no'));
out('Source       : ' . $meta['homepage']);
out('');

if ($doPush && gitOutput(['status', '--porcelain']) !== null) {
    warn('The working tree has uncommitted changes — the pushed image will not match any commit.');
    out('');
}

printPlan($plan, $registry);

if ($dryRun) {
    out('DRY-RUN — nothing was built, pushed, or started.');
}

// ── Build ────────────────────────────────────────────────────────────────────

// A push-only build streams straight to the registry; everything else is loaded
// into the local engine so the smoke test can run against the real artifact.
$pushDirectly = $doPush && !$doSmoke && !$multiPlatform;
$localRefs    = [];

foreach ($plan as $name => $entry) {
    out('');
    out("── {$name} — {$entry['target']['title']}");

    $build            = buildCommand(
        $entry,
        $registry,
        $platforms,
        $meta,
        $version,
        $sha,
        $noCache,
        $pushDirectly
    );
    $localRefs[$name] = $registry . ':' . $entry['tags'][0];

    $code = execCommand($build, $dryRun);
    if ($code !== 0) {
        err("docker buildx build failed for {$name} (exit {$code}).");
    }

    if ($pushDirectly) {
        continue;
    }

    // `--load` normally applies every --tag, but the docker-container driver has
    // been known to import only the first one — tag defensively before pushing.
    foreach ($entry['tags'] as $tag) {
        $ref = $registry . ':' . $tag;
        if ($ref === $localRefs[$name]) {
            continue;
        }

        $code = execCommand(['docker', 'tag', $localRefs[$name], $ref], $dryRun, true);
        if ($code !== 0) {
            err("docker tag failed for {$ref} (exit {$code}).");
        }
    }
}

// ── Smoke test ───────────────────────────────────────────────────────────────

if ($doSmoke) {
    out('');
    out('── Smoke test');

    foreach ($plan as $name => $entry) {
        smokeTest($localRefs[$name], $name, $fixedPort, $keepContainer, $dryRun);
    }
}

// ── Push ─────────────────────────────────────────────────────────────────────

if ($doPush && $doSmoke && !$multiPlatform) {
    out('');
    out('── Push');

    // Pinned tags first, floating tags only after every image passed the smoke
    // test - a floating tag must never point at an unverified image.
    // In --floating-only mode (security rebuilds) pinned release tags are left
    // untouched, so only the floating pass runs.
    $passes = $floatingOnly ? ['floating'] : ['pinned', 'floating'];
    foreach ($passes as $pass) {
        out("  Pass: {$pass}");

        foreach ($plan as $name => $entry) {
            foreach ($entry[$pass] as $tag) {
                $ref  = $registry . ':' . $tag;
                $code = execCommand(['docker', 'push', $ref], $dryRun);
                if ($code !== 0) {
                    err("docker push failed for {$ref} (exit {$code}).");
                }
            }
        }
    }
}

// ── Summary ──────────────────────────────────────────────────────────────────

out('');
out('Images');
out('──────');

foreach ($plan as $name => $entry) {
    out('  ' . $name);
    if ($entry['pinned'] !== []) {
        out('    pinned   : ' . implode(', ', $entry['pinned']));
    }
    if ($entry['floating'] !== []) {
        out('    floating : ' . implode(', ', $entry['floating']));
    }
}

out('');

if ($dryRun) {
    out('DRY-RUN complete. Re-run without --dry-run to execute.');
} elseif ($doPush) {
    out('Done — images pushed to ' . $registry . '.');
} else {
    out('Done — images built locally (add --push to publish).');
}

exit(0);

// ─────────────────────────────────────────────────────────────────────────────
// Target definitions
// ─────────────────────────────────────────────────────────────────────────────

/**
 * All four build targets share one parameterized Dockerfile (docker/Dockerfile);
 * VARIANT selects the web server, FLAVOR selects the deployment flavor.
 *
 * @return array<string, array{dockerfile: string, buildArgs: array<string, string>,
 *                              flavor: string, variant: string,
 *                              shaLabel: string, title: string}>
 */
function targets(): array
{
    $file = 'docker/Dockerfile';

    return [
        'immutable-apache' => [
            'dockerfile' => $file,
            'buildArgs'  => ['VARIANT' => 'apache', 'FLAVOR' => 'immutable'],
            'flavor'     => 'immutable',
            'variant'    => 'apache',
            'shaLabel'   => 'apache',
            'title'      => 'Immutable — Apache + mod_php, code baked into the image',
        ],
        'immutable-nginx' => [
            'dockerfile' => $file,
            'buildArgs'  => ['VARIANT' => 'nginx', 'FLAVOR' => 'immutable'],
            'flavor'     => 'immutable',
            'variant'    => 'nginx',
            'shaLabel'   => 'nginx',
            'title'      => 'Immutable — Nginx + PHP-FPM, code baked into the image',
        ],
        'mutable-apache' => [
            'dockerfile' => $file,
            'buildArgs'  => ['VARIANT' => 'apache', 'FLAVOR' => 'mutable'],
            'flavor'     => 'mutable',
            'variant'    => 'apache',
            'shaLabel'   => 'mutable-apache',
            'title'      => 'Self-managed — Apache, host bind-mount backed auto-update',
        ],
        'mutable-nginx' => [
            'dockerfile' => $file,
            'buildArgs'  => ['VARIANT' => 'nginx', 'FLAVOR' => 'mutable'],
            'flavor'     => 'mutable',
            'variant'    => 'nginx',
            'shaLabel'   => 'mutable-nginx',
            'title'      => 'Self-managed — Nginx + PHP-FPM, host bind-mount backed auto-update',
        ],
    ];
}

/**
 * @param array<string, array<string, string>> $all
 * @param array<string, mixed>                 $opts
 *
 * @return array<string, array<string, string>>
 */
function selectTargets(array $all, array $opts): array
{
    if (isset($opts['only']) && (string) $opts['only'] !== '') {
        $only = (string) $opts['only'];
        if (!isset($all[$only])) {
            err("Unknown --only target \"{$only}\". Valid: " . implode(', ', array_keys($all)) . '.');
        }

        return [$only => $all[$only]];
    }

    $flavor   = isset($opts['flavor']) ? (string) $opts['flavor'] : 'all';
    $flavors  = $flavor === 'all' ? ['immutable', 'mutable'] : splitList($flavor);
    $variants = isset($opts['variants']) ? splitList((string) $opts['variants']) : ['apache', 'nginx'];

    foreach ($flavors as $f) {
        if (!in_array($f, ['immutable', 'mutable'], true)) {
            err("Unknown --flavor \"{$f}\". Valid: all, immutable, mutable.");
        }
    }
    foreach ($variants as $v) {
        if (!in_array($v, ['apache', 'nginx'], true)) {
            err("Unknown --variants value \"{$v}\". Valid: apache, nginx.");
        }
    }

    $selected = [];
    foreach ($all as $name => $target) {
        if (in_array($target['flavor'], $flavors, true)
            && in_array($target['variant'], $variants, true)
        ) {
            $selected[$name] = $target;
        }
    }

    return $selected;
}

// ─────────────────────────────────────────────────────────────────────────────
// Version / metadata resolution
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Same precedence as scripts/prepare-release.php: --version, then an exact git
 * tag, then version.php, then the newest semver tag in the repository. A version
 * found that way produces the full release tag set (including latest/apache/nginx).
 *
 * Only when no version can be determined at all does the run fall back to edge
 * mode, where the `edge*` tags move but `latest` and every version tag stay put.
 * `--edge` forces that behaviour on purpose.
 *
 * @param array<string, mixed> $opts
 *
 * @return array{0: string, 1: string, 2: bool}
 */
function resolveVersion(array $opts, string $rootDir, bool $forceEdge): array
{
    $version = '';
    $source  = '';

    $exactTag = gitOutput(['describe', '--tags', '--exact-match']);

    if (isset($opts['version']) && (string) $opts['version'] !== '') {
        $version = ltrim((string) $opts['version'], 'v');
        $source  = '--version';
    } elseif ($exactTag !== null && preg_match('/^v?\d+\.\d+\.\d+/', $exactTag) === 1) {
        $version = ltrim($exactTag, 'v');
        $source  = "git tag {$exactTag}";
    } else {
        $fromFile = readVersionFile($rootDir . '/version.php');
        if ($fromFile !== null) {
            $version = ltrim($fromFile, 'v');
            $source  = 'version.php';
        } elseif (($latestTag = findLatestReleaseTag()) !== null) {
            $version = ltrim($latestTag, 'v');
            $source  = "git tag {$latestTag}";
        }
    }

    if ($version !== '' && preg_match('/^\d+\.\d+\.\d+/', $version) !== 1) {
        err("Version \"{$version}\" does not look like a semver string (x.y.z).");
    }

    if ($forceEdge) {
        return [
            $version !== '' ? $version : 'edge',
            $version !== '' ? "edge mode (--edge, label from {$source})" : 'edge mode (--edge)',
            true,
        ];
    }

    if ($version !== '') {
        return [$version, $source, false];
    }

    return ['edge', 'no version found — edge mode', true];
}

function readVersionFile(string $path): ?string
{
    if (!is_file($path)) {
        return null;
    }

    $value = require $path;

    return is_string($value) && $value !== '' ? $value : null;
}

/**
 * Newest semver tag, independent of the checked-out commit's ancestry.
 * Release tags may live on a separate commit line, so `git describe` cannot
 * always find them from the default branch.
 */
function findLatestReleaseTag(): ?string
{
    $tags = gitOutput(['tag', '-l', '--sort=-version:refname']);
    if ($tags === null) {
        return null;
    }

    foreach (explode("\n", $tags) as $line) {
        $line = trim($line);
        if ($line !== '' && preg_match('/^v?\d+\.\d+\.\d+/', $line) === 1) {
            return $line;
        }
    }

    return null;
}

/**
 * @return array{homepage: string, license: string, description: string}
 */
function projectMetadata(string $rootDir): array
{
    $defaults = [
        'homepage'    => 'https://github.com/MIDILLI-Tech/vale',
        'license'     => 'AGPL-3.0-or-later',
        'description' => 'Vale - Lightweight PHP CMS & E-Commerce Platform',
    ];

    $file = $rootDir . '/composer.json';
    if (!is_file($file)) {
        return $defaults;
    }

    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) {
        return $defaults;
    }

    return [
        'homepage'    => is_string($data['homepage'] ?? null) ? $data['homepage'] : $defaults['homepage'],
        'license'     => is_string($data['license'] ?? null) ? $data['license'] : $defaults['license'],
        'description' => is_string($data['description'] ?? null)
            ? $data['description']
            : $defaults['description'],
    ];
}

function shortSha(): string
{
    return gitOutput(['rev-parse', '--short=7', 'HEAD']) ?? 'nogit';
}

function gitOutput(array $args): ?string
{
    $proc = @proc_open(
        array_merge(['git'], $args),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );

    if (!is_resource($proc)) {
        return null;
    }

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    if (proc_close($proc) !== 0) {
        return null;
    }

    $result = trim((string) $stdout);

    return $result === '' ? null : $result;
}

// ─────────────────────────────────────────────────────────────────────────────
// Tag computation
// ─────────────────────────────────────────────────────────────────────────────

/**
 * @return array{0: list<string>, 1: list<string>} pinned and floating tags
 */
function computeTags(
    string $target,
    string $version,
    string $sha,
    bool $edge,
    bool $withSha
): array {
    $pinned   = [];
    $floating = [];

    $major = $majorMinor = $version;
    if (preg_match('/^(\d+)\.(\d+)\.\d+/', $version, $m) === 1) {
        $major      = $m[1];
        $majorMinor = $m[1] . '.' . $m[2];
    }

    if ($target === 'immutable-apache') {
        if ($edge) {
            $floating = ['edge', 'edge-apache'];
        } else {
            $pinned   = [$version, "{$version}-apache"];
            $floating = [
                'latest',
                'apache',
                $majorMinor,
                "{$majorMinor}-apache",
            ];
            // Pre-1.0, the "<major>" tag is meaningless (it would move on every
            // 0.x release and only duplicate "latest"), so skip it until 1.0.
            if ((int) $major >= 1) {
                $floating[] = $major;
                $floating[] = "{$major}-apache";
            }
        }
    } elseif ($target === 'immutable-nginx') {
        if ($edge) {
            $floating = ['edge-nginx'];
        } else {
            $pinned   = ["{$version}-nginx"];
            $floating = ['nginx', "{$majorMinor}-nginx"];
            if ((int) $major >= 1) {
                $floating[] = "{$major}-nginx";
            }
        }
    } elseif ($target === 'mutable-apache') {
        $floating = ['mutable-apache'];
    } elseif ($target === 'mutable-nginx') {
        $floating = ['mutable-nginx'];
    }

    if ($withSha) {
        // A commit SHA identifies an exact build, so it belongs with the pinned tags.
        $pinned[] = $sha . '-' . $target;
    }

    return [
        array_values(array_unique($pinned)),
        array_values(array_unique($floating)),
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Commands
// ─────────────────────────────────────────────────────────────────────────────

/**
 * @param array<string, mixed>                $entry
 * @param list<string>                        $platforms
 * @param array{homepage: string, license: string, description: string} $meta
 *
 * @return list<string>
 */
function buildCommand(
    array $entry,
    string $registry,
    array $platforms,
    array $meta,
    string $version,
    string $sha,
    bool $noCache,
    bool $pushDirectly
): array {
    $cmd = ['docker', 'buildx', 'build', '--file', $entry['target']['dockerfile']];

    foreach ($entry['target']['buildArgs'] as $key => $value) {
        $cmd[] = '--build-arg';
        $cmd[] = "{$key}={$value}";
    }

    foreach ($platforms as $platform) {
        $cmd[] = '--platform';
        $cmd[] = $platform;
    }

    foreach ($entry['tags'] as $tag) {
        $cmd[] = '--tag';
        $cmd[] = $registry . ':' . $tag;
    }

    $labels = [
        'org.opencontainers.image.title'       => 'Vale',
        'org.opencontainers.image.description' => $meta['description'],
        'org.opencontainers.image.version'     => $version,
        'org.opencontainers.image.revision'    => $sha,
        'org.opencontainers.image.source'      => $meta['homepage'],
        'org.opencontainers.image.url'         => $meta['homepage'],
        'org.opencontainers.image.licenses'    => $meta['license'],
        'org.opencontainers.image.vendor'      => 'Vale',
    ];

    foreach ($labels as $key => $value) {
        $cmd[] = '--label';
        $cmd[] = "{$key}={$value}";
    }

    if ($noCache) {
        $cmd[] = '--no-cache';
    }

    $cmd[] = $pushDirectly ? '--push' : '--load';
    $cmd[] = '.';

    return $cmd;
}

/**
 * @param list<string> $args
 *
 * @return int process exit code (0 in dry-run)
 */
function execCommand(array $args, bool $dryRun, bool $quiet = false): int
{
    $line = implode(' ', array_map('escapeshellarg', $args));

    if ($dryRun) {
        info('    [dry-run] ' . $line);

        return 0;
    }

    if (!$quiet) {
        info('    $ ' . $line);
    }

    $proc = @proc_open($args, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
    if (!is_resource($proc)) {
        err('Could not start: ' . $args[0]);
    }

    return proc_close($proc);
}

// ─────────────────────────────────────────────────────────────────────────────
// Smoke test
// ─────────────────────────────────────────────────────────────────────────────

function smokeTest(string $image, string $name, int $fixedPort, bool $keep, bool $dryRun): void
{
    $port      = $fixedPort > 0 ? $fixedPort : findFreePort();
    $slug      = str_replace('.', '-', $name) . '-' . $port;
    $container = 'vale-smoke-' . $slug;
    $volume    = 'vale-smoke-' . $slug . '-storage';
    $appKey    = bin2hex(random_bytes(32));

    if ($port === 0) {
        err('Could not allocate a free TCP port for the smoke test.');
    }

    out("  {$name}: {$image} on port {$port}");

    execCommand(['docker', 'rm', '-f', $container], $dryRun, true);

    $run = [
        'docker', 'run', '-d',
        '--name', $container,
        '-p', "127.0.0.1:{$port}:80",
        '-e', "APP_URL=http://localhost:{$port}",
        '-e', 'APP_ENV=production',
        '-e', 'APP_DEBUG=false',
        '-e', "APP_KEY={$appKey}",
        '-e', 'DB_DRIVER=sqlite',
        '-e', 'DB_NAME=storage/database.sqlite',
        '-v', "{$volume}:/var/www/html/storage",
        $image,
    ];

    $code = execCommand($run, $dryRun);
    if ($code !== 0) {
        err("Could not start the smoke container for {$name} (exit {$code}).");
    }

    if ($dryRun) {
        info("    [dry-run] curl http://127.0.0.1:{$port}/api/v1/health?deep=1");
        info("    [dry-run] docker rm -f {$container} && docker volume rm {$volume}");

        return;
    }

    $url      = "http://127.0.0.1:{$port}/api/v1/health?deep=1";
    $deadline = time() + 180;
    $ok       = false;
    $last     = 'no response';

    while (time() < $deadline) {
        $probe = httpProbe($url);
        $last  = "HTTP {$probe['code']} " . $probe['body'];

        if (isHealthy($probe)) {
            $ok = true;
            break;
        }

        // 429 means the deep probe is rate-limited — the app is clearly up, so
        // back off instead of hammering it.
        sleep($probe['code'] === 429 ? 15 : 5);
    }

    if (!$ok) {
        info("    Container logs (tail 60):");
        execCommand(['docker', 'logs', '--tail', '60', $container], false);
        if (!$keep) {
            cleanupSmoke($container, $volume, false);
        }
        err("Smoke test failed for {$name} — last probe: {$last}.");
    }

    out("    OK — {$url} returned 200 ok");
    forbiddenPathSmoke('http://127.0.0.1:' . $port, $container, $volume, $keep);
}

/**
 * Secrets, runtime storage, dependency manifests and app source must never be
 * reachable over HTTP even though the app itself is healthy. Each entry is a
 * path a curious visitor could type; anything below 400 (content served or a
 * redirect leading to content) is a security smell and fails the smoke run.
 */
function forbiddenPathSmoke(string $host, string $container, string $volume, bool $keep): void
{
    $checks = [
        '/.env'                           => '.env (secrets)',
        '/.htaccess'                      => '.htaccess',
        '/.git/config'                    => '.git config',
        '/composer.json'                  => 'dependency manifest',
        '/vendor/composer/installed.json' => 'vendor metadata',
        '/bootstrap/app.php'              => 'PHP source',
        '/storage/database.sqlite'        => 'SQLite database',
        '/storage/logs/errors.log'        => 'error log',
        '/storage/cache/pages/'           => 'page cache',
        '/themes/default/theme.json'      => 'theme config',
        '/uploads/.env'                   => 'dotfile via uploads alias',
        '/uploads/../.env'                => 'traversal via uploads alias',
    ];

    $failures = [];
    foreach ($checks as $path => $what) {
        $probe = forbiddenHttpProbe($host . $path);
        if ($probe['code'] > 0 && $probe['code'] < 400) {
            $failures[] = 'HTTP ' . $probe['code'];
            err("  {$what} is reachable at {$path} — HTTP {$probe['code']}\n    Body: " . $probe['body']);
        } else {
            info('    ✗ ' . $path . ($probe['code'] > 0 ? ' → HTTP ' . $probe['code'] : ' (no response)'));
        }
    }

    if ($failures !== []) {
        info('    Container logs (tail 60):');
        execCommand(['docker', 'logs', '--tail', '60', $container], false);
        if (!$keep) {
            cleanupSmoke($container, $volume, false);
        }
        err('Forbidden-path smoke test failed: ' . implode(', ', $failures));
    }

    out('    OK — forbidden paths (env/storage/db/source) are not served');
}

/**
 * @return array{code: int, body: string}
 */
function forbiddenHttpProbe(string $url): array
{
    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $body = curl_exec($handle);
        $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return ['code' => $code, 'body' => is_string($body) ? substr(trim($body), 0, 200) : ''];
    }

    $context = stream_context_create([
        'http' => ['timeout' => 10, 'ignore_errors' => true, 'method' => 'GET'],
        'ssl'  => ['allow_self_signed' => true, 'verify_peer' => false],
    ]);
    $code = 0;
    $body = @fopen($url, 'r', false, $context);
    if ($body === false) {
        return ['code' => 0, 'body' => ''];
    }
    $meta    = stream_get_meta_data($body);
    $headers = is_array($meta['wrapper_data'] ?? null) ? $meta['wrapper_data'] : [];
    $raw     = stream_get_contents($body);
    fclose($body);
    $code = 0;
    foreach ($headers as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
            $code = (int) $m[1];
            break;
        }
    }
    return ['code' => $code, 'body' => is_string($raw) ? substr(trim($raw), 0, 200) : ''];
}

function cleanupSmoke(string $container, string $volume, bool $keep): void
{
    if ($keep) {
        out("    Container kept: {$container} (docker logs -f {$container})");

        return;
    }

    execCommand(['docker', 'rm', '-f', $container], false, true);
    execCommand(['docker', 'volume', 'rm', $volume], false, true);
}

function findFreePort(): int
{
    $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($socket === false) {
        return 0;
    }

    $name = (string) stream_socket_get_name($socket, false);
    fclose($socket);

    $pos = strrpos($name, ':');

    return $pos === false ? 0 : (int) substr($name, $pos + 1);
}

/**
 * The deep endpoint answers `{"status":"ok","version":...,"timestamp":...}` with
 * HTTP 200, and `{"status":"degraded",...}` with 503. A bare `ok` body is
 * accepted too, so the check keeps working if the payload is ever simplified.
 *
 * @param array{code: int, body: string} $probe
 */
function isHealthy(array $probe): bool
{
    if ($probe['code'] !== 200) {
        return false;
    }

    if (strtolower($probe['body']) === 'ok') {
        return true;
    }

    $decoded = json_decode($probe['body'], true);

    return is_array($decoded)
        && isset($decoded['status'])
        && strtolower((string) $decoded['status']) === 'ok';
}

/**
 * @return array{code: int, body: string}
 */
function httpProbe(string $url): array
{
    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $body = curl_exec($handle);
        $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return ['code' => $code, 'body' => is_string($body) ? trim($body) : ''];
    }

    $context = stream_context_create([
        'http' => ['timeout' => 5, 'ignore_errors' => true, 'method' => 'GET'],
    ]);
    $body   = @fopen($url, 'r', false, $context);
    if ($body === false) {
        return ['code' => 0, 'body' => ''];
    }
    $meta    = stream_get_meta_data($body);
    $headers = is_array($meta['wrapper_data'] ?? null) ? $meta['wrapper_data'] : [];
    $raw     = stream_get_contents($body);
    fclose($body);
    $code   = 0;
    foreach ($headers as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
            $code = (int) $m[1];
            break;
        }
    }

    return ['code' => $code, 'body' => is_string($raw) ? trim($raw) : ''];
}

// ─────────────────────────────────────────────────────────────────────────────
// Output helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * @param array<string, array<string, mixed>> $plan
 */
function printPlan(array $plan, string $registry): void
{
    out('Build plan');
    out('──────────');

    $nameWidth = 18;
    $fileWidth = 40;

    foreach ($plan as $entry) {
        out(sprintf(
            '  %-' . $nameWidth . 's %-' . $fileWidth . 's %s',
            $entry['name'],
            $entry['target']['dockerfile'],
            count($entry['tags']) . ' tag(s)'
        ));

        foreach ($entry['tags'] as $tag) {
            out('      → ' . $registry . ':' . $tag);
        }
    }

    out('');
}

function usageBlock(string $file): string
{
    $source = (string) file_get_contents($file);
    if (preg_match('#/\*\*(.*?)\*/#s', $source, $m) !== 1) {
        return '';
    }

    $lines = preg_split('/\R/', $m[1]) ?: [];
    $lines = array_map(static fn (string $line): string => ltrim($line, " \t"), $lines);
    $lines = array_map(static fn (string $line): string => ltrim($line, '*'), $lines);

    return rtrim(implode("\n", $lines)) . "\n";
}

/**
 * @return list<string>
 */
function splitList(string $value): array
{
    $parts = array_map('trim', explode(',', $value));

    return array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
}

function normalizeRegistry(string $value): string
{
    $value = trim($value, " \t\n\r\0\x0B/");

    return $value === '' ? 'midillitech/vale' : $value;
}

function out(string $message): void
{
    echo $message . "\n";
}

function info(string $message): void
{
    echo $message . "\n";
}

function warn(string $message): void
{
    echo 'WARNING: ' . $message . "\n";
}

function err(string $message): never
{
    fwrite(STDERR, 'ERROR: ' . $message . "\n");
    exit(1);
}
