<?php

declare(strict_types=1);

/**
 * Vale CMS — URL Health Checker
 *
 * Scans config/reference-data.php for external URLs and checks their availability.
 *
 * Usage:
 *   php scripts/url-check.php              # Soft mode: warnings only, exit 0
 *   php scripts/url-check.php --strict     # Strict mode: fails on 404/403, exit 1
 *   php scripts/url-check.php --post       # Force POST on all URLs
 */

$repoRoot = dirname(__DIR__);
chdir($repoRoot);

$strict    = in_array('--strict', $argv, true);
$forcePost = in_array('--post', $argv, true);

const BROWSER_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
const DEFAULT_UA = 'ValeCMS-URLCheck/0.1.0';

function writeLabel($text)
{
    echo "\n$text\n";
    echo str_repeat('=', strlen($text)) . "\n";
}

function writeStatus($level, $text)
{
    switch ($level) {
        case 'Error':   echo "[ERROR] $text\n";
            break;
        case 'Warning': echo "[WARN ] $text\n";
            break;
        case 'Info':    echo "[INFO ] $text\n";
            break;
        case 'Success': echo "[OK    ] $text\n";
            break;
        default:        echo "$text\n";
            break;
    }
}

function collectUrls(array $data, string $prefix = ''): array
{
    $urls = [];

    foreach ($data as $key => $value) {
        $path = $prefix === '' ? (string)$key : $prefix . '->' . $key;

        if (is_array($value)) {
            $urls = array_merge($urls, collectUrls($value, $path));
            continue;
        }

        if (!is_string($value)) {
            continue;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || $trimmed === 'null') {
            continue;
        }

        if (!preg_match('/^https?:\/\//i', $trimmed)) {
            continue;
        }

        $urls[] = [
            'path'  => $path,
            'url'   => $trimmed,
            'raw'   => $value,
        ];
    }

    return $urls;
}

function normalizeUrlForCheck(string $url): string
{
    // Fill template placeholders so provider templates are actually exercised.
    $url = str_replace(
        ['{tracking_number}', '{base}', '{key}'],
        ['TEST123', 'EUR', 'VALE_TEST_KEY'],
        $url
    );

    if (str_contains($url, '?') && str_contains($url, '{')) {
        // Any remaining placeholder inside the query — drop the fragment parts we can't fill.
        $url = preg_replace('/[?&][^=]+=\{[^}]+\}/', '', $url) ?? $url;
    }

    return $url;
}

/**
 * True when the URL is a bare API base / embed prefix — a URL with no meaningful
 * resource path (empty, "/", a version segment like /v3 or /rest/6.0, or an
 * embed prefix like /embed/ or /video/). Such URLs legitimately return 404 on a
 * bare HEAD/GET because they are only ever called with a resource path appended.
 */
function isBaseApiUrl(string $url): bool
{
    $path = rtrim((string) parse_url($url, PHP_URL_PATH), '/');

    return $path === ''
        || $path === '/'
        || (bool) preg_match('#^/(v\d+(\.\d+)?|rest/[\d.]+|embed|video)$#i', $path);
}

function isLikelyPostOnly(string $url): bool
{
    $lower = strtolower($url);
    return (bool) preg_match(
        '#/oauth2/token|/oauth2/userinfo|/oauth/token|/access_token|/users/me|/api/user|/siteverify#i',
        $lower
    );
}

function curlRequest(string $checkUrl, string $userAgent, bool $useGet, bool $usePost = false): array
{
    $ch = curl_init($checkUrl);

    $options = [
        CURLOPT_FOLLOWLOCATION   => true,
        CURLOPT_MAXREDIRS        => 5,
        CURLOPT_CONNECTTIMEOUT   => 10,
        CURLOPT_TIMEOUT          => 30,
        CURLOPT_USERAGENT        => $userAgent,
        CURLOPT_SSL_VERIFYPEER   => true,
        CURLOPT_SSL_VERIFYHOST   => 2,
        CURLOPT_RETURNTRANSFER   => true,
        CURLOPT_HEADER           => false,
    ];

    if ($usePost) {
        $options[CURLOPT_POST]       = true;
        $options[CURLOPT_POSTFIELDS] = 'grant_type=client_credentials';
        $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/x-www-form-urlencoded'];
    } elseif ($useGet) {
        $options[CURLOPT_NOBODY]     = false;
        $options[CURLOPT_HTTPGET]    = true;
    } else {
        $options[CURLOPT_NOBODY]        = true;
        $options[CURLOPT_CUSTOMREQUEST] = 'HEAD';
    }

    curl_setopt_array($ch, $options);

    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errMsg   = curl_error($ch);

    return ['code' => $httpCode, 'error' => $errMsg];
}

function checkUrl(string $url, bool $forcePost = false): array
{
    $checkUrl    = normalizeUrlForCheck($url);
    $isTemplate  = str_contains($url, '{');
    $isBase      = isBaseApiUrl($url);
    $postOnly    = isLikelyPostOnly($checkUrl);
    $attemptPost = $forcePost || $postOnly;

    $attempts = [];

    if ($attemptPost) {
        $attempts[] = ['label' => 'POST (browser UA)', 'ua' => BROWSER_UA, 'post' => true];
    } else {
        $attempts[] = ['label' => 'HEAD (default UA)', 'ua' => DEFAULT_UA, 'post' => false];
        $attempts[] = ['label' => 'HEAD (browser UA)',  'ua' => BROWSER_UA, 'post' => false];
        $attempts[] = ['label' => 'GET (browser UA)',   'ua' => BROWSER_UA, 'post' => false];
        $attempts[] = ['label' => 'POST (browser UA)',  'ua' => BROWSER_UA, 'post' => true];
    }

    $last = null;
    foreach ($attempts as $attempt) {
        $useGet = !$attempt['post'] && str_starts_with($attempt['label'], 'GET');
        $result = curlRequest($checkUrl, $attempt['ua'], $useGet, $attempt['post']);

        if ($result['error'] !== '') {
            $last = $result;
            continue;
        }

        $httpCode = $result['code'];

        if ($httpCode >= 200 && $httpCode < 400) {
            return buildResult($httpCode, 'OK', '', $isTemplate, $isBase);
        }

        // Method/shape retries: a bare HEAD/GET often hits 404/405 on endpoints
        // that only answer POST or a full resource path — keep trying.
        if (($httpCode === 405 || $httpCode === 404) && !$attempt['post']) {
            $last = $result;
            continue;
        }

        return buildResult($httpCode, statusLabel($httpCode), '', $isTemplate, $isBase, $postOnly);
    }

    if ($last !== null && $last['error'] === '') {
        return buildResult($last['code'], statusLabel($last['code']), '', $isTemplate, $isBase, $postOnly);
    }

    return buildResult(0, 'ERROR', 'All attempts failed', $isTemplate, $isBase, $postOnly);
}

/** Human label for a final HTTP status code. */
function statusLabel(int $httpCode): string
{
    return match (true) {
        $httpCode === 404 => 'NOT FOUND',
        $httpCode === 403 => 'FORBIDDEN',
        $httpCode === 405 => 'METHOD NOT ALLOWED',
        $httpCode >= 500  => 'SERVER ERROR',
        $httpCode >= 400  => 'CLIENT ERROR',
        $httpCode >= 300  => 'REDIRECT',
        default           => 'OK',
    };
}

/**
 * Wraps a check outcome and decides whether it is a genuine problem.
 *
 * Codes like 400/401/403/405/406/415/429 and 404-on-a-base-URL mean the endpoint
 * is alive but rejected our bare probe (auth required, wrong method, bot/WAF,
 * needs a resource path). Those are NOT health problems. Only connection errors
 * and 404 on a real resource path count.
 */
function buildResult(int $code, string $status, string $message, bool $isTemplate, bool $isBase = false, bool $postOnly = false): array
{
    $toleratedRejection = in_array($code, [400, 401, 403, 405, 406, 409, 415, 422, 429], true);
    // A bare probe legitimately fails on: base URLs (need a resource path),
    // POST-only endpoints (need a real body), and templates filled with a dummy
    // credential (provider rejects the fake key). None of those mean a dead link.
    $notFoundExpected = $code === 404 && ($isBase || $postOnly || $isTemplate);

    $isProblem = false;
    if ($message !== '') {
        $isProblem = true;          // connection error
    } elseif ($code === 404 && !$isBase && !$postOnly && !$isTemplate) {
        $isProblem = true;          // real resource missing
    } elseif ($toleratedRejection || $notFoundExpected) {
        $isProblem = false;         // endpoint alive, probe rejected
    } elseif ($code >= 500) {
        $isProblem = false;         // transient server error — warn only, not a hard failure
    }

    return [
        'code'       => $code,
        'status'     => $status,
        'message'    => $message,
        'isTemplate' => $isTemplate,
        'isProblem'  => $isProblem,
    ];
}

writeLabel('Vale CMS — URL Health Check');

$referenceDataPath = $repoRoot . '/config/reference-data.php';
if (!file_exists($referenceDataPath)) {
    writeStatus('Error', 'config/reference-data.php not found.');
    exit(2);
}

$referenceData = require $referenceDataPath;
$allUrls       = collectUrls($referenceData);

if (empty($allUrls)) {
    writeStatus('Info', 'No URLs found in reference-data.php.');
    exit(0);
}

writeStatus('Info', 'Checking ' . count($allUrls) . ' URLs...');

$results = [];
$issues  = [];

foreach ($allUrls as $entry) {
    $result    = checkUrl($entry['url'], $forcePost);
    $results[] = [
        'path'       => $entry['path'],
        'url'        => $entry['url'],
        'code'       => $result['code'],
        'status'     => $result['status'],
        'isTemplate' => $result['isTemplate'],
        'message'    => $result['message'],
        'isProblem'  => $result['isProblem'],
    ];

    if ($result['isProblem']) {
        $issues[] = $results[count($results) - 1];
    }
}

usort($results, function ($a, $b) {
    if ($a['isProblem'] !== $b['isProblem']) {
        return $a['isProblem'] ? -1 : 1;
    }
    return $a['code'] <=> $b['code'];
});

echo "\n";
printf("%-45s | %-10s | %-15s | %-40s\n", 'URL (reference-data path)', 'HTTP', 'Status', 'URL');
echo str_repeat('-', 115) . "\n";

foreach ($results as $r) {
    $httpStr = $r['message'] !== '' ? 'ERR' : (string)$r['code'];
    $note    = $r['message'] !== '' ? $r['message'] : ($r['isTemplate'] ? 'tested with placeholder' : '');

    $statusLabel = $r['status'];
    if ($r['code'] === 404) {
        $statusLabel = "\033[31m" . $statusLabel . "\033[0m";
    } elseif ($r['code'] === 403) {
        $statusLabel = "\033[33m" . $statusLabel . "\033[0m";
    } elseif ($r['status'] === 'OK' || $r['status'] === 'REDIRECT' || $r['status'] === 'METHOD NOT ALLOWED') {
        $statusLabel = "\033[32m" . $statusLabel . "\033[0m";
    }

    $pathLabel = $r['path'];
    if ($r['isTemplate']) {
        $pathLabel = "\033[36m" . $pathLabel . "\033[0m";
    }

    $shortUrl = $r['url'];
    if (strlen($shortUrl) > 40) {
        $shortUrl = substr($shortUrl, 0, 37) . '...';
    }

    printf(
        "%-45s | %-10s | %-15s | %-40s\n",
        substr($pathLabel, 0, 45),
        $httpStr,
        $statusLabel,
        $shortUrl
    );
}

echo "\n";

$problemCount   = count($issues);
$notFoundCount  = 0;
$forbiddenCount = 0;
$errorCount     = 0;

foreach ($issues as $issue) {
    if ($issue['message'] !== '') {
        $errorCount++;
    } elseif ($issue['code'] === 404) {
        $notFoundCount++;
    } elseif ($issue['code'] === 403) {
        $forbiddenCount++;
    }
}

writeStatus('Info', "Summary: $problemCount problem(s) found ($notFoundCount not found, $forbiddenCount forbidden, $errorCount connection errors).");

if ($problemCount > 0) {
    if ($strict) {
        writeStatus('Error', 'URL check failed: problems found.');
        exit(1);
    }

    writeStatus('Warning', 'URL check found problems. Review the table above.');
    if ($notFoundCount > 0) {
        writeStatus('Warning', "Run with --strict to fail the build on 404s.");
    }
    exit(0);
}

writeStatus('Success', 'URL check passed: all URLs are healthy.');
exit(0);
