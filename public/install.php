<?php

declare(strict_types=1);

/**
 * Vale Installation Wizard
 *
 * Remove or rename this file after installation is complete.
 */

$rootDir  = dirname(__DIR__);
$lockFile = $rootDir . '/storage/installed.lock';

// Already installed? Redirect.
if (file_exists($lockFile)) {
    $scriptDir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
    header('Location: ' . detectAppUrl() . '/admin');
    exit;
}

session_start();
if (!isset($_SESSION['vl_install'])) {
    $_SESSION['vl_install'] = [];
}
$wiz = &$_SESSION['vl_install'];

$step      = (int) ($_GET['step'] ?? 1);
$errors    = [];
$scriptDir = rtrim(dirname(dirname($_SERVER['PHP_SELF'])), '/\\');
if ($scriptDir === '/' || $scriptDir === '\\') {
    $scriptDir = '';
}

/* ─────────────────────────────── helpers ─────────────────────────────── */

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function val(string $key, string $default = ''): string
{
    global $wiz;

    if (array_key_exists($key, $wiz)) {
        return h((string) $wiz[$key]);
    }

    static $envMap = [
        'app_name'    => 'APP_NAME',
        'app_url'     => 'APP_URL',
        'db_driver'   => 'DB_DRIVER',
        'db_host'     => 'DB_HOST',
        'db_port'     => 'DB_PORT',
        'db_name'     => 'DB_NAME',
        'db_user'     => 'DB_USER',
        'db_pass'     => 'DB_PASS',
        'db_prefix'   => 'DB_PREFIX',
        'admin_name'  => 'ADMIN_NAME',
        'admin_email' => 'ADMIN_EMAIL',
    ];

    $envKey = $envMap[$key] ?? null;
    if ($envKey !== null) {
        $envValue = getenv($envKey);
        if ($envValue !== false && $envValue !== '') {
            return h((string) $envValue);
        }
    }

    return h((string) $default);
}

function checkExt(string $ext): bool
{
    return extension_loaded($ext);
}

function testDbConnection(string $driver, string $host, int $port, string $name, string $user, string $pass): ?string
{
    try {
        if ($driver === 'sqlite') {
            $path = $name;
            if (!str_starts_with($path, '/') && !preg_match('/^[a-zA-Z]:/', $path)) {
                $path = dirname(__DIR__) . '/' . ltrim($path, '/\\');
            }
            $dir = dirname($path);
            if (!is_dir($dir)) {
                return "Directory does not exist: {$dir}";
            }
            if (!is_writable($dir)) {
                return "Directory is not writable: {$dir}";
            }
            if (file_exists($path) && !is_writable($path)) {
                @chmod($path, 0664);
                if (!is_writable($path)) {
                    @unlink($path);
                }
            }
            new PDO("sqlite:{$path}");
        } elseif (in_array($driver, ['pgsql', 'postgres', 'postgresql'], true)) {
            $dsn = "pgsql:host={$host};port={$port};dbname={$name}";
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_TIMEOUT => 5,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            $referenceData = require dirname(__DIR__) . '/config/reference-data.php';
            $requirements  = $referenceData['requirements'] ?? [];
            $minPgsql      = $requirements['min_pgsql']     ?? null;

            if ($minPgsql !== null) {
                $versionString = $pdo->query('SELECT VERSION()')->fetchColumn();
                if ($versionString) {
                    if (preg_match('/PostgreSQL\s+([0-9.]+)/i', (string) $versionString, $matches) || preg_match('/^([0-9.]+)/', (string) $versionString, $matches)) {
                        $dbVersion = $matches[1];
                        if (version_compare($dbVersion, $minPgsql, '<')) {
                            return "Database version not supported. Your database is running PostgreSQL {$dbVersion}, but Vale CMS requires PostgreSQL {$minPgsql}+.";
                        }
                    }
                }
            }
        } else {
            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_TIMEOUT => 5,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            // Retrieve requirements from config
            $referenceData = require dirname(__DIR__) . '/config/reference-data.php';
            $requirements  = $referenceData['requirements'] ?? null;
            if (!$requirements || !isset($requirements['min_mysql']) || !isset($requirements['min_mariadb'])) {
                return "Database requirements are missing from configuration file.";
            }
            $minMysql   = $requirements['min_mysql'];
            $minMariadb = $requirements['min_mariadb'];

            $versionString = $pdo->query('SELECT VERSION()')->fetchColumn();
            if ($versionString) {
                $isMariadb = str_contains(strtolower($versionString), 'mariadb');
                if (preg_match('/^([0-9.]+)/', $versionString, $matches)) {
                    $dbVersion = $matches[1];
                    if ($isMariadb) {
                        if (version_compare($dbVersion, $minMariadb, '<')) {
                            return "Database version not supported. Your database is running MariaDB {$dbVersion}, but Vale CMS requires MariaDB {$minMariadb}+.";
                        }
                    } else {
                        if (version_compare($dbVersion, $minMysql, '<')) {
                            return "Database version not supported. Your database is running MySQL {$dbVersion}, but Vale CMS requires MySQL {$minMysql}+.";
                        }
                    }
                }
            }
        }
        return null; // success
    } catch (PDOException $e) {
        return $e->getMessage();
    }
}

function generateKey(int $len = 32): string
{
    return bin2hex(random_bytes($len / 2));
}

function detectAppUrl(): string
{
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // install.php is in public/, parent dir is the subdirectory (e.g. /test)
    $path   = rtrim(dirname(dirname($_SERVER['PHP_SELF'] ?? '/')), '/\\');
    if ($path === '/' || $path === '\\') {
        $path = '';
    }
    return $scheme . '://' . $host . $path;
}

$referenceData = require $rootDir . '/config/reference-data.php';

// Build available locales from existing language files
$availableLocales = [];
$localeNames      = [
    'en'    => 'English',
    'tr'    => 'Türkçe',
    'de'    => 'Deutsch',
    'fr'    => 'Français',
    'es'    => 'Español',
    'it'    => 'Italiano',
    'pt'    => 'Português',
    'nl'    => 'Nederlands',
    'ru'    => 'Русский',
    'ar'    => 'العربية',
    'zh'    => '中文',
    'ja'    => '日本語',
    'ko'    => '한국어',
    'pl'    => 'Polski',
    'sv'    => 'Svenska',
    'da'    => 'Dansk',
    'fi'    => 'Suomi',
    'nb'    => 'Norsk',
    'cs'    => 'Čeština',
    'hu'    => 'Magyar',
    'ro'    => 'Română',
    'uk'    => 'Українська',
    'el'    => 'Ελληνικά',
    'he'    => 'עברית',
    'hi'    => 'हिन्दी',
    'th'    => 'ไทย',
    'vi'    => 'Tiếng Việt',
    'pt-br' => 'Português (Brasil)',
];
foreach (glob($rootDir . '/resources/lang/*.json') as $file) {
    $code                    = basename($file, '.json');
    $availableLocales[$code] = $localeNames[$code] ?? $code;
}
asort($availableLocales);

/* ─────────────────────────────── POST handling ───────────────────────── */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step === 2) {
        $fields = ['app_name', 'app_url', 'db_driver', 'db_host', 'db_port', 'db_name', 'db_user', 'db_pass', 'db_prefix'];
        foreach ($fields as $f) {
            $wiz[$f] = trim((string) ($_POST[$f] ?? ''));
        }
        if (empty($wiz['app_name'])) {
            $errors[] = 'Site name is required.';
        }
        if (empty($wiz['app_url'])) {
            $errors[] = 'Site URL is required.';
        }
        if ($wiz['db_driver'] === 'mysql') {
            if (empty($wiz['db_host'])) {
                $errors[] = 'Database host is required for MySQL.';
            }
            if (empty($wiz['db_user'])) {
                $errors[] = 'Database user is required for MySQL.';
            }
        }
        if (empty($wiz['db_name'])) {
            $errors[] = $wiz['db_driver'] === 'sqlite' ? 'SQLite path is required.' : 'Database name is required.';
        }
        if ($wiz['db_prefix'] === '') {
            $errors[] = 'Table prefix is required.';
        }
        if (empty($errors)) {
            $dbErr = testDbConnection(
                $wiz['db_driver'] ?: 'mysql',
                $wiz['db_host'] ?: '',
                (int) ($wiz['db_port'] ?: 3306),
                $wiz['db_name'],
                $wiz['db_user'] ?: '',
                $wiz['db_pass'] ?: ''
            );
            if ($dbErr !== null) {
                $msg = $dbErr;
                if (str_contains(strtolower($dbErr), 'could not find driver')) {
                    $driver = match ($wiz['db_driver']) {
                        'sqlite'                          => 'pdo_sqlite',
                        'pgsql', 'postgres', 'postgresql' => 'pdo_pgsql',
                        default                           => 'pdo_mysql',
                    };
                    $msg = "DB connection failed: The PHP extension <strong>{$driver}</strong> is missing or disabled. Please enable it in your php.ini.";
                }
                $errors[] = $msg;
            }
        }
        if (empty($errors)) {
            header('Location: install.php?step=3');
            exit;
        }
    }

    if ($step === 3) {
        // Features & site preferences
        $features                            = (array) ($_POST['features'] ?? []);
        $membershipEnabled                   = in_array('membership', $features, true);
        $wiz['blog_enabled']                 = in_array('blog', $features, true) ? '1' : '0';
        $wiz['shop_enabled']                 = in_array('shop', $features, true) ? '1' : '0';
        $wiz['membership_enabled']           = $membershipEnabled ? '1' : '0';
        $wiz['subscriptions_enabled']        = in_array('subscriptions', $features, true) ? '1' : '0';
        $wiz['pages_enabled']                = in_array('pages', $features, true) ? '1' : '0';
        $wiz['newsletter_enabled']           = in_array('newsletter', $features, true) ? '1' : '0';
        $wiz['form_builder_enabled']         = in_array('form_builder', $features, true) ? '1' : '0';
        $wiz['seo_enabled']                  = in_array('seo', $features, true) ? '1' : '0';
        $wiz['analytics_enabled']            = in_array('analytics', $features, true) ? '1' : '0';
        $wiz['custom_content_types_enabled'] = in_array('custom_content_types', $features, true) ? '1' : '0';
        $wiz['multi_language_content']       = in_array('multi_language_content', $features, true) ? '1' : '0';
        $wiz['advertisement_enabled']        = in_array('advertisement', $features, true) ? '1' : '0';

        // Validation: at least one content feature must be enabled
        $contentFeatures = ['pages', 'blog', 'shop', 'custom_content_types'];
        $hasContent      = false;
        foreach ($contentFeatures as $cf) {
            if (in_array($cf, $features, true)) {
                $hasContent = true;
                break;
            }
        }
        if (!$hasContent) {
            $errors[] = 'Please enable at least one content feature (Pages, Blog, E-Commerce, or Custom Content Types).';
        }

        $selectedCurrency                    = strtoupper(trim((string) ($_POST['currency'] ?? 'EUR')));
        $wiz['currency']                     = preg_match('/^[A-Z]{3}$/', $selectedCurrency) ? $selectedCurrency : 'EUR';
        $selectedCountry                     = strtoupper(trim((string) ($_POST['country'] ?? '')));
        $wiz['shop_country']                 = preg_match('/^[A-Z]{2}$/', $selectedCountry) ? $selectedCountry : '';
        if ($wiz['shop_enabled'] === '1') {
            if ($selectedCurrency === '') {
                $errors[] = 'Default store currency is required when E-Commerce is enabled.';
            }
            if ($wiz['shop_country'] === '') {
                $errors[] = 'Default store country is required when E-Commerce is enabled.';
            }
        }
        $wiz['site_tagline']      = trim((string) ($_POST['site_tagline'] ?? ''));
        $wiz['locale']            = isset($availableLocales[$_POST['locale'] ?? 'en']) ? $_POST['locale'] : 'en';
        $wiz['timezone']          = in_array($_POST['timezone'] ?? 'UTC', DateTimeZone::listIdentifiers(), true)
                                    ? $_POST['timezone'] : 'UTC';
        if (empty($errors)) {
            header('Location: install.php?step=4');
            exit;
        }
    }

    if ($step === 4) {
        $wiz['admin_name']  = trim((string) ($_POST['admin_name']  ?? ''));
        $wiz['admin_email'] = trim((string) ($_POST['admin_email'] ?? ''));
        $wiz['sample_data'] = isset($_POST['sample_data']);
        $adminPass          = (string) ($_POST['admin_pass']  ?? '');
        $adminPass2         = (string) ($_POST['admin_pass2'] ?? '');
        if (empty($wiz['admin_name'])) {
            $errors[] = 'Admin name is required.';
        }
        if (empty($wiz['admin_email'])) {
            $errors[] = 'Admin email is required.';
        }
        if (!filter_var($wiz['admin_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email address.';
        }
        if (strlen($adminPass) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if ($adminPass !== $adminPass2) {
            $errors[] = 'Passwords do not match.';
        }
        if (empty($errors)) {
            $wiz['admin_pass_hash'] = password_hash($adminPass, PASSWORD_BCRYPT);
            header('Location: install.php?step=5');
            exit;
        }
    }
}

/* ─────────────────────────────── Step 5: Run install ─────────────────── */

$installLog    = [];
$installFailed = false;

if ($step === 5 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Validate required session data is present
    if (empty($wiz['db_name']) || empty($wiz['admin_pass_hash'])) {
        header('Location: install.php?step=1');
        exit;
    }

    // Prepare variables (execution deferred to HTML for progressive output)
    $appKey        = generateKey(64);
    $appName       = $wiz['app_name'] ?? 'Vale';
    $appUrl        = rtrim($wiz['app_url'] ?? 'http://localhost', '/');
    $sessionSecure = str_starts_with($appUrl, 'https://') ? 'true' : 'false';
    $dbDriver      = $wiz['db_driver']   ?? 'mysql';
    $dbHost        = $wiz['db_host']     ?? '127.0.0.1';
    $dbPort        = (int) ($wiz['db_port'] ?: 3306);
    $dbName        = $wiz['db_name'];
    $dbUser        = $wiz['db_user']     ?? 'root';
    $dbPass        = $wiz['db_pass']     ?? '';
    $dbPrefix      = $wiz['db_prefix']   ?? 'vl_';
    $locale        = $wiz['locale']      ?? 'en';
    $timezone      = $wiz['timezone']    ?? 'UTC';
    $publicDir     = dirname($_SERVER['PHP_SELF']);
    $basePath      = rtrim(dirname($publicDir), '/\\');
    $basePath      = str_replace('\\', '/', $basePath);
    if ($basePath === '/' || $basePath === '') {
        $basePath = '';
    }
    $localeList    = implode(' | ', array_keys($availableLocales));
    $shopEnabled   = ($wiz['shop_enabled'] ?? '0') === '1';
    $envContent    = <<<ENV
# =============================================================================
# Vale — Environment Configuration
# Generated by the installation wizard. Review and adjust as needed.
# =============================================================================

# Application
APP_ENV=production           # Environment: production | dev
APP_DEBUG=false              # Show detailed error info (set true only in dev)
APP_URL={$appUrl}            # Canonical base URL (must match actual domain)
APP_KEY={$appKey}            # Encryption key (64 hex chars); never change after install
ENCRYPT_PII=false            # Encrypt customer/order address PII at rest (true | false). Enabling it makes addresses unreadable if APP_KEY is lost/changed.
APP_LOCALE={$locale}         # Default locale: $localeList

# Database
DB_DRIVER={$dbDriver}        # Database driver: mysql | sqlite | pgsql
DB_HOST={$dbHost}            # Database hostname (mysql, pgsql only, Docker compose uses "db*")
DB_PORT={$dbPort}            # Database port (mysql or pgsql only)
DB_NAME={$dbName}            # Database name (mysql or pgsql) or path (sqlite, e.g. storage/database.sqlite)
DB_USER={$dbUser}            # Database username (mysql or pgsql only)
DB_PASS={$dbPass}            # Database password (mysql or pgsql only)
DB_CHARSET=utf8mb4           # Database charset
DB_PREFIX={$dbPrefix}        # Database table prefix (e.g. vl_)

# Session
SESSION_LIFETIME=120         # Session lifetime in minutes (default 120 = 2 hours)
SESSION_SECURE={$sessionSecure}   # Send cookie only over HTTPS; set true in production with HTTPS
SESSION_HTTPONLY=true        # Block JavaScript access to session cookie (XSS protection)
SESSION_SAMESITE=Lax         # SameSite policy: Lax | Strict | None (CSRF protection)

# Storage
STORAGE_DRIVER=local         # Storage driver: local (only local supported currently)

# Cache
CACHE_DRIVER=file            # Cache driver: file (only file supported currently)
CACHE_TTL=3600               # Data cache TTL in seconds (default 3600 = 1 hour)
PAGE_CACHE_ENABLED=true      # Enable full-page HTML cache for guest visitors
PAGE_CACHE_TTL=86400         # Page cache TTL in seconds (default 86400 = 24h; falls back to CACHE_TTL)

# Rate Limiting
RATE_LIMIT_LOGIN=5           # Max login attempts per minute per IP (brute force protection)
RATE_LIMIT_FORM=3            # Max form submissions per minute per IP (spam protection)
RATE_LIMIT_API=60            # Max API requests per minute per IP (abuse protection)
TRUSTED_PROXIES=             # Trusted proxy IPs for correct client detection (e.g. behind Cloudflare, Nginx). Leave empty to use REMOTE_ADDR.

# Update Checker
UPDATE_PUBLIC_KEY_ED25519=JOVBRKCK2mIIDwY5FgkAwMmHSo1lTNo/V1EjqGicQxQ=   # Base64-encoded Ed25519 public key for release signature verification (official Vale release key)
ENV;

    // Append any extra vars from .env.example that are not in the generated content
    // (e.g. UPDATE_MANIFEST_URL, UPDATE_PUBLIC_KEY_ED25519, etc.)
    $envExamplePath = $rootDir . '/.env.example';
    if (file_exists($envExamplePath)) {
        $extraLines   = [];
        $existingKeys = [];
        // Collect keys already in $envContent
        foreach (explode("\n", $envContent) as $line) {
            if (preg_match('/^([A-Z_]+)=/', $line, $m)) {
                $existingKeys[$m[1]] = true;
            }
        }
        foreach (file($envExamplePath, FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^([A-Z_]+)=/', $line, $m) && !isset($existingKeys[$m[1]])) {
                $extraLines[] = $line;
            }
        }
        if ($extraLines !== []) {
            $envContent .= "\n" . implode("\n", $extraLines) . "\n";
        }
    }
}

/* ─────────────────────────────── Requirements ─────────────────────────── */

if (!isset($referenceData['requirements'])) {
    die("Fatal Error: Central requirements configuration is missing from reference-data.php.");
}

$requirementsData = $referenceData['requirements'];

$minPhpVersion     = $requirementsData['min_php']     ?? null;
$minMysqlVersion   = $requirementsData['min_mysql']   ?? null;
$minMariadbVersion = $requirementsData['min_mariadb'] ?? null;

if (!$minPhpVersion || !$minMysqlVersion || !$minMariadbVersion) {
    die("Fatal Error: Required software versions (PHP, MySQL, or MariaDB) are not defined in reference-data.php.");
}

$phpOk         = version_compare(PHP_VERSION, $minPhpVersion, '>=');
$vendorDir     = $rootDir . '/vendor';
$vendorOk      = is_dir($vendorDir) && is_file($vendorDir . '/autoload.php');

$extStatus = [];
foreach ($requirementsData['required_extensions'] as $ext => $desc) {
    $extStatus[$ext] = checkExt($ext);
}

// Driver specific extensions
$pdoMysqlOk  = checkExt('pdo_mysql');
$pdoPgsqlOk  = checkExt('pdo_pgsql');
$pdoSqliteOk = checkExt('pdo_sqlite');

// Recommended (optional) extensions — shown as a warning, do NOT block install
$extOptStatus = [];
foreach ($requirementsData['recommended_extensions'] as $ext => $desc) {
    $extOptStatus[$ext] = checkExt($ext);
}

$curlAvailable   = function_exists('curl_init');
$allowUrlFopen   = filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN);
$httpTransportOk = $curlAvailable || $allowUrlFopen;

$storageDirs = [
    'storage'                  => 0755,
    'storage/logs'             => 0755,
    'storage/cache'            => 0755,
    'storage/sessions'         => 0755,
    'storage/uploads'          => 0755,
    'storage/ratelimit'        => 0755,
    'storage/cache/container'  => 0755,
    'storage/backups'          => 0755,
    'storage/framework'        => 0755,
    'public'                   => 0755,
    'themes'                   => 0755,
    'content/plugins'          => 0755,
    'content/mu-plugins'       => 0755,
];
$dirStatus    = [];
$dirsCreated  = [];

foreach ($storageDirs as $dir => $perm) {
    $fullPath = $rootDir . '/' . $dir;
    if (is_dir($fullPath)) {
        $dirStatus[$dir] = is_writable($fullPath);
    } else {
        // Attempt to create directory recursively, even if root directory itself is not writable
        @mkdir($fullPath, $perm, true);
        $dirStatus[$dir] = is_dir($fullPath) && is_writable($fullPath);
        if ($dirStatus[$dir]) {
            $dirsCreated[] = $dir;
        }
    }
}

// Environment helper to detect Docker containers
$isDocker = file_exists('/.dockerenv')                                                                      ||
            (file_exists('/proc/1/cgroup') && str_contains(@file_get_contents('/proc/1/cgroup'), 'docker')) ||
            getenv('APP_ENV') !== false;

$reqPass = $phpOk
    && $vendorOk
    && !in_array(false, $extStatus, true)
    && !in_array(false, $dirStatus, true)
    && ($pdoMysqlOk || $pdoSqliteOk);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Vale — Installation Wizard</title>
<script>
  (function() {
    var savedTheme = localStorage.getItem('vl_install_theme');
    var systemPrefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    var theme = savedTheme || (systemPrefersDark ? 'dark' : 'light');
    if (theme === 'light') {
      document.documentElement.setAttribute('data-theme', 'light');
    } else {
      document.documentElement.removeAttribute('data-theme');
    }
  })();
</script>
<style>
  :root {
    --bg-color: #0B0F19;
    --card-bg: rgba(255, 255, 255, 0.05);
    --card-border: rgba(255, 255, 255, 0.08);
    --card-shadow: rgba(0, 0, 0, 0.4);
    --text-primary: #cbd5e1;
    --text-secondary: #94a3b8;
    --text-muted: #64748b;
    --heading-color: #d1d5db;
    --input-bg: rgba(255, 255, 255, 0.06);
    --input-border: rgba(255, 255, 255, 0.1);
    --input-text: #e2e8f0;
    --placeholder-color: rgba(255, 255, 255, 0.25);
    --select-option-bg: #1a1f2e;
    --accent-color: #e2e8f0;
    --focus-shadow: rgba(226, 232, 240, 0.15);
    --feature-card-bg: rgba(255, 255, 255, 0.04);
    --feature-card-border: rgba(255, 255, 255, 0.08);
    --feature-card-hover-bg: rgba(255, 255, 255, 0.08);
    --feature-card-hover-border: rgba(255, 255, 255, 0.15);
    --btn-bg: #d1d5db;
    --btn-text: #0B0F19;
    --btn-sec-bg: rgba(255, 255, 255, 0.06);
    --btn-sec-border: rgba(255, 255, 255, 0.08);
    --btn-sec-text: #94a3b8;
    --btn-sec-hover-bg: rgba(255, 255, 255, 0.1);
    --check-border: rgba(255, 255, 255, 0.06);
    --success-bg: rgba(74, 222, 128, 0.1);
    --success-text: #4ade80;
    --error-bg: rgba(220, 38, 38, 0.12);
    --error-border: rgba(220, 38, 38, 0.3);
    --error-text: #fca5a5;
    --warning-bg: rgba(251, 191, 36, 0.08);
    --warning-text: #fbbf24;
    --warning-border: rgba(251, 191, 36, 0.25);
  }
  :root[data-theme="light"] {
    --bg-color: #f3f4f6;
    --card-bg: #ffffff;
    --card-border: #e5e7eb;
    --card-shadow: rgba(0, 0, 0, 0.08);
    --text-primary: #1f2937;
    --text-secondary: #4b5563;
    --text-muted: #6b7280;
    --heading-color: #111827;
    --input-bg: #f9fafb;
    --input-border: #d1d5db;
    --input-text: #1f2937;
    --placeholder-color: rgba(0, 0, 0, 0.35);
    --select-option-bg: #ffffff;
    --accent-color: #2563eb;
    --focus-shadow: rgba(37, 99, 235, 0.15);
    --feature-card-bg: #f9fafb;
    --feature-card-border: #e5e7eb;
    --feature-card-hover-bg: #f3f4f6;
    --feature-card-hover-border: #cbd5e1;
    --btn-bg: #111827;
    --btn-text: #ffffff;
    --btn-sec-bg: #f3f4f6;
    --btn-sec-border: #e5e7eb;
    --btn-sec-text: #4b5563;
    --btn-sec-hover-bg: #e5e7eb;
    --check-border: #e5e7eb;
    --success-bg: rgba(34, 197, 94, 0.1);
    --success-text: #15803d;
    --error-bg: rgba(239, 68, 68, 0.1);
    --error-border: rgba(239, 68, 68, 0.3);
    --error-text: #b91c1c;
    --warning-bg: rgba(217, 119, 6, 0.08);
    --warning-text: #b45309;
    --warning-border: rgba(217, 119, 6, 0.25);
  }
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
         background: var(--bg-color); color: var(--text-secondary); min-height: 100vh; display: flex;
         align-items: center; justify-content: center; padding: 2rem; transition: background 0.15s, color 0.15s; }
  .card { background: var(--card-bg); backdrop-filter: blur(12px);
          -webkit-backdrop-filter: blur(12px);
          border: 1px solid var(--card-border);
          border-radius: 1.25rem; box-shadow: 0 8px 40px var(--card-shadow);
          max-width: 760px; width: 100%; padding: 3rem; position: relative; transition: background 0.15s, border-color 0.15s; }
  h1 { font-size: 1.6rem; font-weight: 700; color: var(--heading-color); margin-bottom: .25rem; }
  h2 { color: var(--heading-color); }
  .subtitle { color: var(--text-muted); margin-bottom: 2rem; font-size: .9rem; }
  .steps { display: flex; gap: .5rem; margin-bottom: 2rem; }
  .step-dot { flex: 1; height: 4px; border-radius: 4px; background: var(--card-border); }
  .step-dot.done { background: var(--text-secondary); }
  .step-dot.active { background: var(--accent-color); }
  label { display: block; font-size: .85rem; font-weight: 600; color: var(--text-primary); margin-bottom: .35rem; }
  input[type=text], input[type=email], input[type=password], input[type=url], input[type=number], select {
    width: 100%; padding: .55rem .75rem; background: var(--input-bg);
    border: 1px solid var(--input-border); border-radius: .5rem;
    font-size: .95rem; color: var(--input-text); outline: none; transition: border .15s, background .15s, color 0.15s; margin-bottom: 1rem; }
  input::placeholder { color: var(--placeholder-color); }
  select option { background: var(--select-option-bg); color: var(--input-text); }
  input:focus, select:focus { border-color: var(--accent-color); box-shadow: 0 0 0 3px var(--focus-shadow); }
  .feature-card { display:flex; flex-direction:column; align-items:flex-start; gap:0; background:var(--feature-card-bg); border:1px solid var(--feature-card-border); border-radius:.5rem; padding:.75rem; cursor:default; font-weight:500; color:var(--text-primary); transition:border-color .15s,background .15s; }
  .feature-card:hover { background:var(--feature-card-hover-bg); border-color:var(--feature-card-hover-border); }
  .feature-card-header { display:flex; align-items:center; justify-content:space-between; width:100%; gap: 0.5rem; }
  .feature-title-area { display:flex; align-items:center; gap:0.25rem; cursor:pointer; user-select:none; flex:1; }
  .feature-title-text { font-size:0.95rem; font-weight:600; color:var(--text-primary); }
  .feature-card label { display:flex; align-items:center; margin-bottom:0; cursor:pointer; }
  .feature-card input { width:1.1rem; height:1.1rem; accent-color:var(--accent-color); margin:0; outline:none; box-shadow:none; }
  .feature-card input:focus-visible { outline:none; box-shadow:none; }
  .feature-desc { display:none; color:var(--text-secondary); font-size:.8rem; line-height:1.4; margin-top:.4rem; margin-left: 1.5rem; }
  .detail-toggle { cursor:pointer; padding: 2px 4px; transition:transform 0.15s; font-size: 0.75rem; color: var(--text-muted); user-select: none; display: inline-block; }
  .hint-text { font-size:.8rem; color:var(--text-muted); margin-top:.4rem; margin-bottom:1rem; }
  .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
  .dir-perms { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); column-gap: 1rem; row-gap: 0; margin: 0; }
  .btn { display: inline-block; background: var(--btn-bg); color: var(--btn-text); border: none;
         border-radius: .5rem; padding: .65rem 1.5rem; font-size: .95rem; font-weight: 700;
         cursor: pointer; transition: opacity .15s, background 0.15s, color 0.15s; width: 100%; margin-top: .5rem; }
  .btn:hover { opacity: .85; }
  .btn-sec { background: var(--btn-sec-bg); color: var(--btn-sec-text); border: 1px solid var(--btn-sec-border); }
  .btn-sec:hover { background: var(--btn-sec-hover-bg); }
  .errors { background: var(--error-bg); border: 1px solid var(--error-border); border-radius: .5rem;
            padding: 1rem; margin-bottom: 1.5rem; color: var(--error-text); font-size: .875rem; }
  .errors li { margin-left: 1.25rem; }
  .check { display: flex; align-items: center; gap: .6rem; padding: .4rem 0;
           font-size: .875rem; border-bottom: 1px solid var(--check-border); }
  .check:last-child { border-bottom: none; }
  .icon-ok  { color: var(--success-text); font-weight: 700; }
  .icon-err { color: var(--error-text); font-weight: 700; }
  .log-item { padding: .35rem 0; font-size: .85rem; display: flex; gap: .5rem; }
  .success-box { text-align: center; padding: 0.5rem 0; }
  .success-box .big-icon { font-size: 3.5rem; margin-bottom: 1rem; }
  .success-box h2 { font-size: 1.4rem; font-weight: 700; color: var(--success-text); margin-bottom: .5rem; }
  .success-box p { color: var(--text-secondary); margin-bottom: 1.5rem; }
  .section-title { font-size: .75rem; font-weight: 700; text-transform: uppercase;
                   letter-spacing: .08em; color: var(--text-muted); margin: 1.25rem 0 .5rem; }
  code { background: var(--btn-sec-bg); color: var(--text-primary); padding: 2px 4px; border-radius: 4px; font-family: monospace; }
  .theme-toggle-btn {
    position: absolute;
    top: 1.5rem;
    right: 1.5rem;
    background: var(--btn-sec-bg);
    border: 1px solid var(--btn-sec-border);
    color: var(--text-primary);
    padding: 0.4rem 0.6rem;
    border-radius: 0.5rem;
    font-size: 0.85rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 0.35rem;
    font-weight: 500;
    transition: background 0.15s, border-color 0.15s;
  }
  .theme-toggle-btn:hover {
    background: var(--btn-sec-hover-bg);
  }
</style>
</head>
<body>
<div class="card">
  <button type="button" class="theme-toggle-btn" onclick="toggleTheme()" aria-label="Toggle dark/light theme">
    <span class="theme-icon">☀️</span> <span class="theme-label">Light Mode</span>
  </button>
  <script>
    function updateThemeUI() {
      var theme = document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
      var label = document.querySelector('.theme-toggle-btn .theme-label');
      var icon = document.querySelector('.theme-toggle-btn .theme-icon');
      if (label && icon) {
        if (theme === 'light') {
          label.textContent = 'Dark Mode';
          icon.textContent = '🌙';
        } else {
          label.textContent = 'Light Mode';
          icon.textContent = '☀️';
        }
      }
    }
    function toggleTheme() {
      var current = document.documentElement.getAttribute('data-theme');
      if (current === 'light') {
        document.documentElement.removeAttribute('data-theme');
        localStorage.setItem('vl_install_theme', 'dark');
      } else {
        document.documentElement.setAttribute('data-theme', 'light');
        localStorage.setItem('vl_install_theme', 'light');
      }
      updateThemeUI();
    }
    document.addEventListener('DOMContentLoaded', updateThemeUI);
  </script>
  <h1><img src="assets/img/vale-gray.png" alt="Vale" style="height:2rem;width:auto;vertical-align:middle;margin-top:-5px;"> Vale</h1>
  <p class="subtitle">Installation Wizard</p>
  <div class="steps">
    <?php for ($i = 1; $i <= 5; $i++) : ?>
    <div class="step-dot <?= $i < $step ? 'done' : ($i == $step ? 'active' : '') ?>"></div>
    <?php endfor; ?>
  </div>

<?php if (!empty($errors)) : ?>
<div class="errors"><ul><?php foreach ($errors as $e) :
    ?><li><?= h($e) ?></li><?php
endforeach; ?></ul></div>
<?php endif; ?>

<?php if (!$vendorOk) : ?>
<div class="errors" style="border-color: var(--warning-border); color: var(--warning-text); background: var(--warning-bg);">
  <strong>Dependencies missing:</strong> The <code>vendor/</code> directory was not found.
  Run <code>composer install</code> in the project root directory before continuing with the installation.
</div>
<?php endif; ?>

<?php /* ────────────── STEP 1: Requirements ────────────── */ ?>
<?php if ($step === 1) : ?>
  <h2 style="font-size:1.1rem;font-weight:700;margin-bottom:1rem;">System Requirements</h2>
  <p style="margin-bottom:1.5rem;color:var(--text-secondary);">Check your server environment and required PHP extensions before proceeding. Hover <span style="cursor:help;font-size:12px;color:var(--text-muted);">ℹ</span> to read why an extension is required or recommended.</p>

  <div class="section-title">PHP Version</div>
  <div class="check">
    <span class="<?= $phpOk ? 'icon-ok' : 'icon-err' ?>"><?= $phpOk ? '✓' : '✗' ?></span>
    PHP <?= h(PHP_VERSION) ?> (requires <?= h($minPhpVersion) ?>+)
  </div>

  <div class="section-title">Dependencies</div>
  <div class="check">
    <span class="<?= $vendorOk ? 'icon-ok' : 'icon-err' ?>"><?= $vendorOk ? '✓' : '✗' ?></span>
    vendor/ directory
    <?php if (!$vendorOk) : ?>
    <span style="color:var(--error-text);font-size:.75rem;margin-left:.5rem;">(run <code>composer install</code> first)</span>
    <?php endif; ?>
  </div>

<div class="row2">

<div>
<div class="section-title">Required Extensions</div>
    <?php foreach ($extStatus as $ext => $ok) : ?>
  <div class="check">
    <span class="<?= $ok ? 'icon-ok' : 'icon-err' ?>"><?= $ok ? '✓' : '✗' ?></span>
        <?= h($ext) ?>
    <span title="<?= h($requirementsData['required_extensions'][$ext] ?? '') ?>" style="cursor:help;margin-left:4px;font-size:11px;color:var(--text-muted);">&#9432;</span>
  </div>
    <?php endforeach; ?>

  <div class="check">
    <span class="<?= ($pdoMysqlOk || $pdoPgsqlOk || $pdoSqliteOk) ? 'icon-ok' : 'icon-err' ?>"><?= ($pdoMysqlOk || $pdoPgsqlOk || $pdoSqliteOk) ? '✓' : '✗' ?></span>
    pdo_mysql / pdo_pgsql / pdo_sqlite
    <span title="At least one database driver is required" style="cursor:help;margin-left:4px;font-size:11px;color:var(--text-muted);">&#9432;</span>
  </div>
  <div class="check">
    <span class="<?= $allowUrlFopen ? 'icon-ok' : 'icon-err' ?>"><?= $allowUrlFopen ? '✓' : '✗' ?></span>
    allow_url_fopen
    <span title="HTTP transport fallback when ext-curl is unavailable" style="cursor:help;margin-left:4px;font-size:11px;color:var(--text-muted);">&#9432;</span>
  </div>
</div>

<div>
<div class="section-title">Recommended Extensions</div>
    <?php foreach ($extOptStatus as $ext => $ok) : ?>
  <div class="check">
    <span style="<?= $ok ? 'color:var(--success-text);font-weight:700' : 'color:var(--warning-text);font-weight:700' ?>"><?= $ok ? '✓' : '⚠' ?></span>
        <?= h($ext) ?>
    <span title="<?= h($requirementsData['recommended_extensions'][$ext] ?? '') ?>" style="cursor:help;margin-left:4px;font-size:11px;color:var(--text-muted);">&#9432;</span>
  </div>
    <?php endforeach; ?>
    <?php if (!$httpTransportOk) : ?>
  <div style="margin-top:.5rem;color:var(--warning-text);font-size:.8rem;">
    ⚠ Neither ext-curl nor allow_url_fopen is available. Payment provider "Test connection" and webhooks may fail.
  </div>
    <?php endif; ?>
</div>

</div>

  <div class="section-title">Directory Permissions</div>
  <div class="dir-perms">
    <?php foreach ($dirStatus as $dir => $ok) : ?>
  <div class="check">
    <span class="<?= $ok ? 'icon-ok' : 'icon-err' ?>"><?= $ok ? '✓' : '✗' ?></span>
        <?= h($dir) ?>/
        <?php if ($ok && in_array($dir, $dirsCreated, true)) : ?>
    <span style="color:var(--text-muted);font-size:.75rem;margin-left:.5rem;">(created)</span>
        <?php endif; ?>
  </div>
    <?php endforeach; ?>
  </div>

    <?php if (in_array(false, $dirStatus, true)) : ?>
        <?php if ($isDocker) : ?>
      <div style="margin-top:0.75rem;background:var(--warning-bg);border:1px solid var(--warning-border);border-radius:.5rem;padding:.85rem 1rem;font-size:.8rem;color:var(--warning-text);">
        <strong>🐳 Docker Permission Hint:</strong> Some directories are not writable. If you are mounting volumes or using bind-mounts, please run the following command in your terminal to fix container permissions:<br>
        <code style="display:block;margin-top:0.35rem;padding:0.35rem;background:var(--card-bg);border:1px solid var(--card-border);word-break:break-all;color:var(--text-primary);">docker exec -it -u 0 vale-apache chown -R www-data:www-data /var/www/html/storage /var/www/html/public</code>
        (Replace <code>vale-apache</code> with your running container name like <code>vale-nginx</code> or <code>vale-app</code> if different).
      </div>
        <?php else : ?>
      <div style="margin-top:0.75rem;color:var(--error-text);font-size:.8rem;">
        ⚠️ Please check permissions of these folders on your server and make sure they are writable by your web server process (e.g. <code>www-data</code> or <code>apache</code>).
      </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($reqPass) : ?>
  <a href="install.php?step=2" class="btn" style="display:block;text-align:center;text-decoration:none;margin-top:1.5rem;">
    Continue →
  </a>
    <?php else : ?>
  <div style="margin-top:1.5rem;color:var(--error-text);font-size:.85rem;">
    ✗ Fix the issues above before continuing.
  </div>
    <?php endif; ?>

    <?php /* ────────────── STEP 2: DB & Site ────────────── */ ?>
<?php elseif ($step === 2) : ?>
  <h2 style="font-size:1.1rem;font-weight:700;margin-bottom:1rem;">Site &amp; Database</h2>
  <p style="margin-bottom:1.5rem;color:var(--text-secondary);">Enter your site name, URL, and database credentials to connect your application.</p>
  <form method="post" action="install.php?step=2" onsubmit="var b=this.querySelector('button[type=submit]');b.disabled=true;b.innerHTML='⏳ Testing…'">
    <label>Site Name *</label>
    <input type="text" name="app_name" value="<?= val('app_name', 'Vale') ?>" required>

    <label>Site URL *</label>
    <input type="url" name="app_url" value="<?= val('app_url', detectAppUrl()) ?>" required>

    <div class="section-title">Database</div>
    <label for="db_driver">Database Driver</label>
    <select id="db_driver" name="db_driver" onchange="toggleDbFields(this.value)">
      <option value="mysql" <?= val('db_driver', 'mysql')  === 'mysql' ? 'selected' : '' ?> data-available="<?= $pdoMysqlOk ? '1' : '0' ?>">MySQL</option>
      <option value="pgsql" <?= in_array(val('db_driver', 'mysql'), ['pgsql', 'postgres', 'postgresql'], true) ? 'selected' : '' ?> data-available="<?= $pdoPgsqlOk ? '1' : '0' ?>">PostgreSQL</option>
      <option value="sqlite" <?= val('db_driver', 'mysql') === 'sqlite' ? 'selected' : '' ?> data-available="<?= $pdoSqliteOk ? '1' : '0' ?>">SQLite</option>
    </select>

    <div id="driver-error" style="display:none;background:var(--error-bg);border:1px solid var(--error-border);border-radius:.5rem;padding:.75rem;margin-bottom:1rem;color:var(--error-text);font-size:.85rem;">
      <strong>Driver missing:</strong> The <span id="missing-driver-name"></span> extension is not enabled in your PHP configuration.
    </div>

    <div id="mysql-fields" style="<?= val('db_driver', 'mysql') === 'sqlite' ? 'display:none;' : '' ?>">
      <div class="row2">
        <div>
          <label>Host *</label>
          <input type="text" name="db_host" value="<?= val('db_host', '127.0.0.1') ?>">
        </div>
        <div>
          <label>Port</label>
          <input type="number" name="db_port" value="<?= val('db_port', '3306') ?>">
        </div>
      </div>
      <div class="row2">
        <div>
          <label>Database Username *</label>
          <input type="text" name="db_user" value="<?= val('db_user', 'root') ?>">
        </div>
        <div>
          <label>Database Password</label>
          <input type="password" name="db_pass" value="<?= val('db_pass') ?>">
        </div>
      </div>
    </div>

    <div class="row2">
      <div>
        <label id="db-name-label" for="db_name"><?= val('db_driver', 'mysql') === 'sqlite' ? 'SQLite Path *' : 'Database Name *' ?></label>
        <input type="text" id="db_name" name="db_name" value="<?= val('db_name', val('db_driver', 'mysql') === 'sqlite' ? 'storage/database.sqlite' : 'vale') ?>" required>
      </div>
      <div>
        <label>Table Prefix</label>
        <input type="text" name="db_prefix" value="<?= val('db_prefix', 'vl_') ?>" placeholder="e.g. vl_ (default)">
      </div>
    </div>

    <script>
    function toggleDbFields(driver) {
      var sel = document.getElementById('db_driver');
      var opt = sel.options[sel.selectedIndex];
      var available = opt.dataset.available === '1';
      var errBox = document.getElementById('driver-error');
      var submitBtn = document.querySelector('button[type=submit]');

      if (!available) {
        errBox.style.display = 'block';
        var extName = 'pdo_mysql';
        if (driver === 'sqlite') extName = 'pdo_sqlite';
        if (driver === 'pgsql' || driver === 'postgres' || driver === 'postgresql') extName = 'pdo_pgsql';
        document.getElementById('missing-driver-name').textContent = extName;
        submitBtn.disabled = true;
        submitBtn.style.opacity = '0.5';
      } else {
        errBox.style.display = 'none';
        submitBtn.disabled = false;
        submitBtn.style.opacity = '1';
      }

      document.getElementById('mysql-fields').style.display = (driver === 'sqlite') ? 'none' : 'block';
      document.getElementById('db-name-label').textContent = (driver === 'sqlite') ? 'SQLite Path *' : 'Database Name *';

      var portInput = document.getElementsByName('db_port')[0];
      var userInput = document.getElementsByName('db_user')[0];
      if (driver === 'pgsql') {
        if (portInput && portInput.value === '3306') portInput.value = '5432';
        if (userInput && userInput.value === 'root') userInput.value = 'postgres';
      } else if (driver === 'mysql') {
        if (portInput && portInput.value === '5432') portInput.value = '3306';
        if (userInput && userInput.value === 'postgres') userInput.value = 'root';
      }

      var nameInput = document.getElementsByName('db_name')[0];
      if (driver === 'sqlite' && nameInput.value === 'vale') {
        nameInput.value = 'storage/database.sqlite';
      } else if ((driver === 'mysql' || driver === 'pgsql') && nameInput.value === 'storage/database.sqlite') {
        nameInput.value = 'vale';
      }
    }
    </script>
    <div style="display:flex;gap:.75rem;justify-content:space-between;align-items:center;flex-wrap:wrap;">
      <a href="install.php?step=1" class="btn btn-sec" style="flex:1 1 0;min-width:0;text-align:center;text-decoration:none;">← Back</a>
      <button type="submit" class="btn" style="flex:1 1 0;min-width:0;">Test Connection &amp; Continue →</button>
    </div>
  </form>

    <?php /* ────────────── STEP 3: Features & Preferences ────────────── */ ?>
<?php elseif ($step === 3) : ?>
  <h2 style="font-size:1.1rem;font-weight:700;margin-bottom:1rem;">Features &amp; Preferences</h2>
  <p style="margin-bottom:1.5rem;color:var(--text-secondary);">Choose the features you want enabled for this installation. If you change your mind, you can enable or disable them after installation.</p>
  <form method="post" action="install.php?step=3" onsubmit="var b=this.querySelector('button[type=submit]');b.disabled=true;b.innerHTML='⏳ Saving…'">

    <div class="section-title">Enable Features</div>
    <p style="font-size:0.8rem; color:var(--text-muted); margin-bottom:1rem; margin-top:-0.25rem;">💡 Click on feature names to see more details.</p>
    <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1rem;margin-bottom:1.25rem;">
      <div class="feature-card">
        <div class="feature-card-header">
          <div class="feature-title-area" onclick="toggleFeatureDesc(this)">
            <span class="detail-toggle">▶</span>
            <span class="feature-title-text">📄 Pages</span>
          </div>
          <label>
            <input type="checkbox" name="features[]" value="pages" <?= ($wiz['pages_enabled'] ?? '1') !== '0' ? 'checked' : '' ?>>
          </label>
        </div>
        <div class="feature-desc">Create landing pages and static content like About or Contact.</div>
      </div>
      <div class="feature-card">
        <div class="feature-card-header">
          <div class="feature-title-area" onclick="toggleFeatureDesc(this)">
            <span class="detail-toggle">▶</span>
            <span class="feature-title-text">📝 Blog</span>
          </div>
          <label>
            <input type="checkbox" name="features[]" value="blog" <?= ($wiz['blog_enabled'] ?? '1') !== '0' ? 'checked' : '' ?>>
          </label>
        </div>
        <div class="feature-desc">Publish posts, updates, and storytelling content.</div>
      </div>
      <div class="feature-card">
        <div class="feature-card-header">
          <div class="feature-title-area" onclick="toggleFeatureDesc(this)">
            <span class="detail-toggle">▶</span>
            <span class="feature-title-text">🛒 E-Commerce</span>
          </div>
          <label>
            <input type="checkbox" name="features[]" value="shop" <?= ($wiz['shop_enabled'] ?? '1') !== '0' ? 'checked' : '' ?>>
          </label>
        </div>
        <div class="feature-desc">Enable products, cart, checkout, one-time payments, and subscriptions with recurring billing.</div>
      </div>
      <div class="feature-card">
        <div class="feature-card-header">
          <div class="feature-title-area" onclick="toggleFeatureDesc(this)">
            <span class="detail-toggle">▶</span>
            <span class="feature-title-text">🗓️ Subscriptions</span>
          </div>
          <label>
            <input type="checkbox" name="features[]" value="subscriptions" <?= ($wiz['subscriptions_enabled'] ?? '1') !== '0' ? 'checked' : '' ?>>
          </label>
        </div>
        <div class="feature-desc">Enable donations, recurring billing and subscription-based plans.</div>
      </div>
      <div class="feature-card">
        <div class="feature-card-header">
          <div class="feature-title-area" onclick="toggleFeatureDesc(this)">
            <span class="detail-toggle">▶</span>
            <span class="feature-title-text">👥 Registration</span>
          </div>
          <label>
            <input type="checkbox" name="features[]" value="membership" <?= ($wiz['membership_enabled'] ?? '1') !== '0' ? 'checked' : '' ?>>
          </label>
        </div>
        <div class="feature-desc">Enable external member registrations via email or SSO, and account management.</div>
      </div>
      <div class="feature-card">
        <div class="feature-card-header">
          <div class="feature-title-area" onclick="toggleFeatureDesc(this)">
            <span class="detail-toggle">▶</span>
            <span class="feature-title-text">📰 Newsletter</span>
          </div>
          <label>
            <input type="checkbox" name="features[]" value="newsletter" <?= ($wiz['newsletter_enabled'] ?? '1') !== '0' ? 'checked' : '' ?>>
          </label>
        </div>
        <div class="feature-desc">Collect emails and send announcements to subscribers, create automations.</div>
      </div>
      <div class="feature-card">
        <div class="feature-card-header">
          <div class="feature-title-area" onclick="toggleFeatureDesc(this)">
            <span class="detail-toggle">▶</span>
            <span class="feature-title-text">🧩 Form Builder</span>
          </div>
          <label>
            <input type="checkbox" name="features[]" value="form_builder" <?= ($wiz['form_builder_enabled'] ?? '1') !== '0' ? 'checked' : '' ?>>
          </label>
        </div>
        <div class="feature-desc">Build custom forms to receive emails, surveys, or to embed into another page.</div>
      </div>
      <div class="feature-card">
        <div class="feature-card-header">
          <div class="feature-title-area" onclick="toggleFeatureDesc(this)">
            <span class="detail-toggle">▶</span>
            <span class="feature-title-text">🗂️ Custom Content Types</span>
          </div>
          <label>
            <input type="checkbox" name="features[]" value="custom_content_types" <?= ($wiz['custom_content_types_enabled'] ?? '1') !== '0' ? 'checked' : '' ?>>
          </label>
        </div>
        <div class="feature-desc">Define custom post types like portfolio, recipes, or testimonials with their own fields.</div>
      </div>
      <div class="feature-card">
        <div class="feature-card-header">
          <div class="feature-title-area" onclick="toggleFeatureDesc(this)">
            <span class="detail-toggle">▶</span>
            <span class="feature-title-text">📢 Advertisement</span>
          </div>
          <label>
            <input type="checkbox" name="features[]" value="advertisement" <?= ($wiz['advertisement_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
          </label>
        </div>
        <div class="feature-desc">Enable advertisement slots and shortcodes for platforms like AdSense or Bing Ads.</div>
      </div>
      <div class="feature-card">
        <div class="feature-card-header">
          <div class="feature-title-area" onclick="toggleFeatureDesc(this)">
            <span class="detail-toggle">▶</span>
            <span class="feature-title-text">🌍 Multi-Language Content</span>
          </div>
          <label>
            <input type="checkbox" name="features[]" value="multi_language_content" <?= ($wiz['multi_language_content'] ?? '0') === '1' ? 'checked' : '' ?>>
          </label>
        </div>
        <div class="feature-desc">Enable translations of posts, pages, products, and more into multiple languages.</div>
      </div>
      <div class="feature-card">
        <div class="feature-card-header">
          <div class="feature-title-area" onclick="toggleFeatureDesc(this)">
            <span class="detail-toggle">▶</span>
            <span class="feature-title-text">🔎 SEO</span>
          </div>
          <label>
            <input type="checkbox" name="features[]" value="seo" <?= ($wiz['seo_enabled'] ?? '1') !== '0' ? 'checked' : '' ?>>
          </label>
        </div>
        <div class="feature-desc">Optimize metadata and improve search engine visibility. Customize URLs with redirect support.</div>
      </div>
      <div class="feature-card">
        <div class="feature-card-header">
          <div class="feature-title-area" onclick="toggleFeatureDesc(this)">
            <span class="detail-toggle">▶</span>
            <span class="feature-title-text">📊 Analytics</span>
          </div>
          <label>
            <input type="checkbox" name="features[]" value="analytics" <?= ($wiz['analytics_enabled'] ?? '1') !== '0' ? 'checked' : '' ?>>
          </label>
        </div>
        <div class="feature-desc">Track page views, referrers, and visitor statistics.</div>
      </div>
    </div>
    <script>
      function toggleFeatureDesc(titleArea) {
        var card = titleArea.closest('.feature-card');
        var toggleBtn = card.querySelector('.detail-toggle');
        var desc = card.querySelector('.feature-desc');
        if (desc.style.display === 'block') {
          desc.style.display = 'none';
          toggleBtn.style.transform = 'rotate(0deg)';
        } else {
          desc.style.display = 'block';
          toggleBtn.style.transform = 'rotate(90deg)';
        }
      }
    </script>


    <div class="section-title">Site Details</div>
    <label>Site Tagline</label>
    <input type="text" name="site_tagline" value="<?= val('site_tagline') ?>" placeholder="e.g. The fastest way to build a site">
    <p class="hint-text">A short phrase shown in previews and some themes. It helps describe your site in search snippets, social cards, and header sections.</p>

    <div class="row2">
      <div>
        <label>Default Language</label>
        <select name="locale">
          <?php foreach ($availableLocales as $code => $name) : ?>
          <option value="<?= $code ?>" <?= ($wiz['locale'] ?? 'en') === $code ? 'selected' : '' ?>><?= $name ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Timezone</label>
        <select name="timezone">
          <?php foreach (($referenceData['timezones'] ?? []) as $tz => $label) : ?>
          <option value="<?= $tz ?>" <?= ($wiz['timezone'] ?? 'UTC') === $tz ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div id="shop-defaults-title" class="section-title" style="display:none;">Store Defaults</div>
    <div class="row2" id="shop-defaults-row" style="display:none;margin-bottom:1.25rem;">
      <div id="shop-country-wrap" style="display:none;">
        <label>Default Store Country *</label>
        <select id="shop-country" name="country" data-selected="<?= h($wiz['shop_country'] ?? '') ?>">
        </select>
        <p class="hint-text">This country will be used as your store's default tax location.</p>
      </div>
      <div id="shop-currency-wrap" style="display:none;">
        <label>Default Store Currency *</label>
        <select id="shop-currency" name="currency" data-selected="<?= h($wiz['currency'] ?? 'EUR') ?>">
        </select>
        <p class="hint-text">Default currency is enabled after install. Additional currencies can be enabled later in Settings.</p>
      </div>
    </div>

    <div style="display:flex;gap:.75rem;justify-content:space-between;align-items:center;flex-wrap:wrap;">
      <a href="install.php?step=2" class="btn btn-sec" style="flex:1 1 0;min-width:0;text-align:center;text-decoration:none;">← Back</a>
      <button type="submit" class="btn" style="flex:1 1 0;min-width:0;">Save &amp; Continue →</button>
    </div>
  </form>
  <script>
    (function(){
      var shopCbs = document.querySelectorAll('input[value="shop"]');
      var currencyWrap = document.getElementById('shop-currency-wrap');
      var countryWrap  = document.getElementById('shop-country-wrap');
      var currencySel  = document.getElementById('shop-currency');
      var countrySel   = document.getElementById('shop-country');
      var savedCountry = countrySel ? countrySel.dataset.selected : '';
      var savedCurrency = currencySel ? currencySel.dataset.selected : '';
      var lang = navigator.language || 'en';

      function populateStoreDefaults() {
        if (countrySel) {
          var regions = [];
          if (typeof Intl.supportedValuesOf === 'function') {
            try {
              regions = Intl.supportedValuesOf('region');
            } catch (e) {
              regions = [];
            }
          }
          if (!regions.length) {
            regions = ['AF','AX','AL','DZ','AS','AD','AO','AI','AQ','AG','AR','AM','AW','AU','AT','AZ','BS','BH','BD','BB','BY','BE','BZ','BJ','BM','BT','BO','BQ','BA','BW','BV','BR','IO','BN','BG','BF','BI','KH','CM','CA','CV','KY','CF','TD','CL','CN','CX','CC','CO','KM','CG','CD','CK','CR','CI','HR','CU','CW','CY','CZ','DK','DJ','DM','DO','EC','EG','SV','GQ','ER','EE','SZ','ET','FK','FO','FJ','FI','FR','GF','PF','TF','GA','GM','GE','DE','GH','GI','GR','GL','GD','GP','GU','GT','GG','GN','GW','GY','HT','HM','VA','HN','HK','HU','IS','IN','ID','IR','IQ','IE','IM','IL','IT','JM','JP','JE','JO','KZ','KE','KI','KP','KR','KW','KG','LA','LV','LB','LS','LR','LY','LI','LT','LU','MO','MK','MG','MW','MY','MV','ML','MT','MH','MQ','MR','MU','YT','MX','FM','MD','MC','MN','ME','MS','MA','MZ','MM','NA','NR','NP','NL','NC','NZ','NI','NE','NG','NU','NF','MP','NO','OM','PK','PW','PS','PA','PG','PY','PE','PH','PN','PL','PT','PR','QA','RE','RO','RU','RW','BL','SH','KN','LC','MF','PM','VC','WS','SM','ST','SA','SN','RS','SC','SL','SG','SX','SK','SI','SB','SO','ZA','GS','SS','ES','LK','SD','SR','SJ','SE','CH','SY','TW','TJ','TZ','TH','TL','TG','TK','TO','TT','TN','TR','TM','TC','TV','UG','UA','AE','GB','US','UM','UY','UZ','VU','VE','VN','VG','VI','WF','EH','YE','ZM','ZW','XK'];
          }
          var names = null;
          try {
            names = new Intl.DisplayNames(lang, { type: 'region' });
          } catch (e) {
            names = null;
          }

          var regionItems = regions
            .filter(function(code) { return /^[A-Z]{2}$/.test(code); })
            .map(function(code) {
              var name = code;
              if (names) {
                try { name = names.of(code) || code; } catch (e) { name = code; }
              }
              return { code: code, name: name };
            })
            .sort(function(a, b) { return a.name.localeCompare(b.name, lang); });

          var placeholder = document.createElement('option');
          placeholder.value = '';
          placeholder.disabled = true;
          placeholder.selected = !savedCountry;
          placeholder.textContent = 'Select a country';
          countrySel.appendChild(placeholder);
          regionItems.forEach(function(item){
            var opt = document.createElement('option');
            opt.value = item.code;
            opt.textContent = item.name + ' (' + item.code + ')';
            if (item.code === savedCountry) {
              opt.selected = true;
            }
            countrySel.appendChild(opt);
          });
        }

        if (currencySel) {
          var codes = [];
          if (typeof Intl.supportedValuesOf === 'function') {
            try {
              codes = Intl.supportedValuesOf('currency');
            } catch (e) {
              codes = [];
            }
          }
          if (!codes.length) {
            codes = ['AED','AFN','ALL','AMD','ANG','AOA','ARS','AUD','AWG','AZN','BAM','BBD','BDT','BGN','BHD','BIF','BMD','BND','BOB','BOV','BRL','BSD','BTN','BWP','BYN','BZD','CAD','CDF','CHE','CHF','CHW','CLF','CLP','CNY','COP','COU','CRC','CUC','CUP','CVE','CZK','DJF','DKK','DOP','DZD','EGP','ERN','ETB','EUR','FJD','FKP','GBP','GEL','GHS','GIP','GMD','GNF','GTQ','GYD','HKD','HNL','HRK','HTG','HUF','IDR','ILS','INR','IQD','IRR','ISK','JMD','JOD','JPY','KES','KGS','KHR','KMF','KPW','KRW','KWD','KYD','KZT','LAK','LBP','LKR','LRD','LSL','LYD','MAD','MDL','MGA','MKD','MMK','MNT','MOP','MRU','MUR','MVR','MWK','MXN','MXV','MYR','MZN','NAD','NGN','NIO','NOK','NPR','NZD','OMR','PAB','PEN','PGK','PHP','PKR','PLN','PYG','QAR','RON','RSD','RUB','RWF','SAR','SBD','SCR','SDG','SEK','SGD','SHP','SLL','SOS','SRD','SSP','STN','SVC','SYP','SZL','THB','TJS','TMT','TND','TOP','TRY','TTD','TWD','TZS','UAH','UGX','USD','USN','UYI','UYU','UYW','UZS','VES','VND','VUV','WST','XAF','XAG','XAU','XBA','XBB','XBC','XBD','XOF','XPD','XPF','XPT','XSU','XTS','XUA','XXX','YER','ZAR','ZMW','ZWL'];
          }
          var names = null;
          try {
            names = new Intl.DisplayNames(lang, { type: 'currency' });
          } catch (e) {
            names = null;
          }

          var currencyItems = codes.map(function(code){
            var name = code;
            if (names) {
              try { name = names.of(code) || code; } catch (e) { name = code; }
            }
            var symbol = '';
            try {
              var parts = Intl.NumberFormat(lang, { style: 'currency', currency: code }).formatToParts(0);
              for (var j = 0; j < parts.length; j++) {
                if (parts[j].type === 'currency') { symbol = parts[j].value; break; }
              }
            } catch (e) {}
            return { code: code, name: name, symbol: symbol };
          })
          .sort(function(a, b) { return a.name.localeCompare(b.name, lang); });

          var placeholder = document.createElement('option');
          placeholder.value = '';
          placeholder.disabled = true;
          placeholder.selected = !savedCurrency;
          placeholder.textContent = 'Select a currency';
          currencySel.appendChild(placeholder);
          currencyItems.forEach(function(item){
            var opt = document.createElement('option');
            opt.value = item.code;
            var label = item.name + ' (' + (item.symbol ? item.symbol + ' — ' : '') + item.code + ')';
            opt.textContent = label;
            if (item.code === savedCurrency) {
              opt.selected = true;
            }
            currencySel.appendChild(opt);
          });
        }
      }

      populateStoreDefaults();

      function syncNotice(){
        var isShop = shopCbs[0].checked;
        var title = document.getElementById('shop-defaults-title');
        var defaultsRow = document.getElementById('shop-defaults-row');
        if (title) {
          title.style.display = isShop ? 'block' : 'none';
        }
        if (defaultsRow) {
          defaultsRow.style.display = isShop ? 'grid' : 'none';
        }
        currencyWrap.style.display = isShop ? 'block' : 'none';
        countryWrap.style.display = isShop ? 'block' : 'none';
        if (currencySel) {
          currencySel.required = isShop;
        }
        if (countrySel) {
          countrySel.required = isShop;
        }
      }
      shopCbs.forEach(function(cb){ cb.addEventListener('change', syncNotice); });
      syncNotice();
    })();
  </script>

    <?php /* ────────────── STEP 4: Admin User ────────────── */ ?>
<?php elseif ($step === 4) : ?>
  <h2 style="font-size:1.1rem;font-weight:700;margin-bottom:1rem;">Admin Account</h2>
  <p style="margin-bottom:1.5rem;color:#94a3b8;">Create the first administrator account for managing your new site.</p>
  <form method="post" action="install.php?step=4" onsubmit="var b=this.querySelector('button[type=submit]');b.disabled=true;b.innerHTML='⏳ Installing…'">
    <div class="row2">
      <div>
        <label>Full Name *</label>
        <input type="text" name="admin_name" value="<?= val('admin_name') ?>" required>
      </div>
      <div>
        <label>Email Address *</label>
        <input type="email" name="admin_email" value="<?= val('admin_email') ?>" required>
      </div>
    </div>

    <div class="row2">
      <div>
        <label>Password * (min. 8 characters)</label>
        <input type="password" name="admin_pass" required minlength="8">
      </div>
      <div>
        <label>Confirm Password *</label>
        <input type="password" name="admin_pass2" required minlength="8">
      </div>
    </div>

    <label>
      <input type="checkbox" name="sample_data" value="1" <?= !empty($wiz['sample_data']) ? 'checked' : '' ?>>
      Add sample data
    </label>
    <p class="hint-text">Add example pages, products, orders, users, and other demo content. Leave unchecked for a clean installation. Includes a demo subscriber login (<code>user@example.com</code> / <code>password123</code>).</p>

    <div style="display:flex;gap:.75rem;justify-content:space-between;align-items:center;flex-wrap:wrap;">
      <a href="install.php?step=3" class="btn btn-sec" style="flex:1 1 0;min-width:0;text-align:center;text-decoration:none;">← Back</a>
      <button type="submit" class="btn" style="flex:1 1 0;min-width:0;">Create Account &amp; Install →</button>
    </div>
  </form>

    <?php /* ────────────── STEP 5: Installing (progressive output) ──────── */ ?>
<?php elseif ($step === 5) : ?>
  <div id="install-progress">
  <h2 style="font-size:1.1rem;font-weight:700;margin-bottom:1rem;">Installing, please wait…</h2>
  <p style="margin-bottom:1.5rem;color:var(--text-secondary);">The system is applying settings, running migrations, and creating your admin account.</p>
  <script>window._installDirty=!0;window.addEventListener('beforeunload',function(e){if(window._installDirty){e.preventDefault();e.returnValue=''}});</script>
    <?php
              ob_implicit_flush(true);
        if (ob_get_level()) {
            ob_end_flush();
        }

        function eLog($ok, $msg)
        {
            $icon = $ok ? '✓' : '✗';
            $cls  = $ok ? 'icon-ok' : 'icon-err';
            echo '<div class="log-item"><span class="' . $cls . '">' . $icon . '</span><span>' . h($msg) . '</span></div>';
            flush();
        }

        $installFailed = false;

        // 1) Write .env
        $envPath = $rootDir . '/.env';
        if (file_put_contents($envPath, $envContent) === false) {
            $installFailed = true;
            eLog(false, 'Could not write .env file. Check directory permissions.');
        } else {
            eLog(true, '.env file written.');
        }

if (!$installFailed) {
    echo '<div class="log-item"><span style="color:#94a3b8;">⏳</span><span style="color:#94a3b8;">Running database migrations, please wait…</span></div>';
    flush();
}

// 2) Run migrations
if (!$installFailed) {
    require_once $rootDir . '/vendor/autoload.php';
    date_default_timezone_set($timezone);
    $phinxApp = new \Phinx\Console\PhinxApplication();
    $phinxApp->setAutoExit(false);
    $input  = new \Symfony\Component\Console\Input\ArrayInput([
    'command'           => 'migrate',
    '--configuration'   => $rootDir . '/phinx.php',
    '--environment'     => 'default',
    '--no-interaction'  => true,
    ]);
    $output   = new \Symfony\Component\Console\Output\BufferedOutput();
    $exitCode = $phinxApp->run($input, $output);
    if ($exitCode !== 0) {
        $installFailed = true;
        eLog(false, 'Migrations failed: ' . $output->fetch());
    } else {
        eLog(true, 'Database migrations completed.');
    }
}

// 3) Run seeders
if (!$installFailed) {
    $phinxApp = new \Phinx\Console\PhinxApplication();
    $phinxApp->setAutoExit(false);
    $input  = new \Symfony\Component\Console\Input\ArrayInput([
    'command'           => 'seed:run',
    '--configuration'   => $rootDir . '/phinx.php',
    '--environment'     => 'default',
    '--no-interaction'  => true,
    '-s'                => ['InitialSeeder'],
    ]);
    $output    = new \Symfony\Component\Console\Output\BufferedOutput();
    $exitCode2 = $phinxApp->run($input, $output);
    if ($exitCode2 !== 0) {
        $installFailed = true;
        eLog(false, 'Seeders failed: ' . $output->fetch());
    } else {
        eLog(true, 'Initial data seeded.');
    }
}

// 4) Create admin user + assign super_admin role
if (!$installFailed) {
    try {
        if ($dbDriver === 'sqlite') {
            $path = $dbName;
            if (!str_starts_with($path, '/') && !preg_match('/^[a-zA-Z]:/', $path)) {
                $path = $rootDir . '/' . ltrim($path, '/\\');
            }
            $pdo = new PDO("sqlite:{$path}");
        } else {
            $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE    => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT    => 10,
            ]);
        }
        $now  = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            "INSERT INTO {$dbPrefix}users (email, password, name, locale, is_active, email_verified_at, created_at)
               VALUES (?, ?, ?, 'en', 1, ?, ?)"
        );
        $stmt->execute([
            $wiz['admin_email'],
            $wiz['admin_pass_hash'],
            $wiz['admin_name'],
            $now,
            $now,
        ]);
        $userId  = (int) $pdo->lastInsertId();
        $roleRow = $pdo->query("SELECT id FROM {$dbPrefix}roles WHERE slug = 'super_admin' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($roleRow) {
            $pdo->prepare("INSERT INTO {$dbPrefix}user_roles (user_id, role_id, created_at) VALUES (?, ?, ?)")
                ->execute([$userId, $roleRow['id'], $now]);
        }
        eLog(true, 'Admin user created.');
    } catch (Exception $e) {
        $installFailed = true;
        eLog(false, 'Could not create admin user: ' . $e->getMessage());
    }
}

// 4b) Optional sample data
$sampleDataRequested = !empty($wiz['sample_data']);
if (!$installFailed && $sampleDataRequested) {
    $phinxApp = new \Phinx\Console\PhinxApplication();
    $phinxApp->setAutoExit(false);
    $input  = new \Symfony\Component\Console\Input\ArrayInput([
    'command'           => 'seed:run',
    '--configuration'   => $rootDir . '/phinx.php',
    '--environment'     => 'default',
    '--no-interaction'  => true,
    '-s'                => ['DemoSeeder'],
    ]);
    $output    = new \Symfony\Component\Console\Output\BufferedOutput();
    $exitCode3 = $phinxApp->run($input, $output);
    if ($exitCode3 !== 0) {
        $installFailed = true;
        eLog(false, 'Sample data failed: ' . $output->fetch());
    } else {
        $seedOutput = trim($output->fetch());
        eLog(true, 'Sample data added.' . ($seedOutput !== '' ? ' ' . $seedOutput : ''));
    }
} elseif (!$installFailed) {
    eLog(true, 'Sample data skipped (the checkbox was not selected).');
}

// 4c) Verify the administrator role assignment after all seeders have run.
if (!$installFailed) {
    try {
        $roleRow = $pdo->query("SELECT id FROM {$dbPrefix}roles WHERE slug = 'super_admin' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$roleRow) {
            throw new RuntimeException('The super_admin role was not found after seeding.');
        }

        $roleCheck = $pdo->prepare(
            "SELECT 1 FROM {$dbPrefix}user_roles WHERE user_id = ? AND role_id = ? LIMIT 1"
        );
        $roleCheck->execute([$userId, $roleRow['id']]);
        if (!$roleCheck->fetchColumn()) {
            $pdo->prepare("INSERT INTO {$dbPrefix}user_roles (user_id, role_id, created_at) VALUES (?, ?, ?)")
                ->execute([$userId, $roleRow['id'], $now]);
            eLog(true, 'Administrator role assignment verified.');
        }
    } catch (Throwable $e) {
        $installFailed = true;
        eLog(false, 'Could not assign the administrator role: ' . $e->getMessage());
    }
}

// 5) Write feature & site settings to DB
if (!$installFailed) {
    try {
        if (!isset($pdo)) {
            if ($dbDriver === 'sqlite') {
                $path = $dbName;
                if (!str_starts_with($path, '/') && !preg_match('/^[a-zA-Z]:/', $path)) {
                    $path = $rootDir . '/' . ltrim($path, '/\\');
                }
                $pdo = new PDO("sqlite:{$path}");
            } else {
                $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
                $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            }
        }
        $now           = $now ?? (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $upsertSetting = function (PDO $db, string $group, string $key, string $value, string $type) use ($now, $dbPrefix): void {
            $existing = $db->prepare("SELECT id FROM {$dbPrefix}settings WHERE `key` = ? LIMIT 1");
            $existing->execute([$key]);
            if ($existing->fetchColumn() !== false) {
                $db->prepare("UPDATE {$dbPrefix}settings SET `value` = ?, updated_at = ? WHERE `key` = ?")
                   ->execute([$value, $now, $key]);
            } else {
                $db->prepare("INSERT INTO {$dbPrefix}settings (group_name, `key`, `value`, `type`, is_autoload, created_at) VALUES (?, ?, ?, ?, 1, ?)")
                   ->execute([$group, $key, $value, $type, $now]);
            }
        };
        $upsertSetting($pdo, 'general', 'site_name', $appName, 'string');
        $upsertSetting($pdo, 'general', 'admin_email', $wiz['admin_email'] ?? 'admin@example.com', 'string');
        $upsertSetting($pdo, 'general', 'default_locale', $wiz['locale'] ?? 'en', 'string');
        $upsertSetting($pdo, 'general', 'timezone', $wiz['timezone'] ?? 'UTC', 'string');
        $upsertSetting($pdo, 'general', 'blog_enabled', $wiz['blog_enabled'] ?? '1', 'boolean');
        $upsertSetting($pdo, 'general', 'shop_enabled', $wiz['shop_enabled'] ?? '1', 'boolean');
        $upsertSetting($pdo, 'general', 'registration_enabled', $wiz['membership_enabled'] ?? '1', 'boolean');
        $upsertSetting($pdo, 'general', 'pages_enabled', $wiz['pages_enabled'] ?? '1', 'boolean');
        $upsertSetting($pdo, 'general', 'newsletter_enabled', $wiz['newsletter_enabled'] ?? '1', 'boolean');
        $upsertSetting($pdo, 'general', 'form_builder_enabled', $wiz['form_builder_enabled'] ?? '1', 'boolean');
        $upsertSetting($pdo, 'general', 'seo_enabled', $wiz['seo_enabled'] ?? '1', 'boolean');
        $upsertSetting($pdo, 'general', 'analytics_enabled', $wiz['analytics_enabled'] ?? '1', 'boolean');
        $upsertSetting($pdo, 'general', 'custom_content_types_enabled', $wiz['custom_content_types_enabled'] ?? '1', 'boolean');
        $upsertSetting($pdo, 'cron', 'cron_mode', 'page_load', 'string');
        $upsertSetting($pdo, 'cron', 'cron_interval', '5', 'string');
        $multiLangSetting = $sampleDataRequested ? '1' : ($wiz['multi_language_content'] ?? '0');
        $upsertSetting($pdo, 'content', 'multi_language_content', $multiLangSetting, 'boolean');
        if ($multiLangSetting === '1') {
            if ($sampleDataRequested) {
                $langs        = array_values(array_unique(array_filter([$wiz['locale'] ?? 'en', 'en', 'tr'])));
                $contentLangs = implode(',', $langs);
            } else {
                $contentLangs = $wiz['locale'] ?? 'en';
            }
        } else {
            $contentLangs = '';
        }
        $upsertSetting($pdo, 'content', 'content_languages', $contentLangs, 'string');
        $upsertSetting($pdo, 'content', 'i18n_display_originals_fallback', '1', 'boolean');
        $upsertSetting($pdo, 'content', 'i18n_fallback_content_notice', '1', 'boolean');
        $upsertSetting($pdo, 'content', 'advertisement_enabled', $wiz['advertisement_enabled'] ?? '0', 'boolean');
        $upsertSetting($pdo, 'content', 'advertisement_code', '', 'string');
        $upsertSetting($pdo, 'content', 'advertisement_placement', 'manual', 'string');
        $upsertSetting($pdo, 'ecommerce', 'subscriptions_enabled', $wiz['subscriptions_enabled'] ?? '1', 'boolean');
        if (($wiz['shop_enabled'] ?? '0') === '1') {
            $storeCurrency = strtoupper((string) ($wiz['currency'] ?? 'EUR'));
            if (!preg_match('/^[A-Z]{3}$/', $storeCurrency)) {
                $storeCurrency = 'EUR';
            }
            $upsertSetting($pdo, 'ecommerce', 'currency', $storeCurrency, 'string');
            $upsertSetting($pdo, 'ecommerce', 'allowed_currencies', '', 'string');
            $upsertSetting($pdo, 'ecommerce', 'blocked_currencies', '', 'string');
            $upsertSetting($pdo, 'ecommerce', 'shop_country', $wiz['shop_country'] ?? 'DE', 'string');
        }
        if (!empty($wiz['site_tagline'])) {
            $upsertSetting($pdo, 'general', 'site_tagline', $wiz['site_tagline'], 'string');
        }
        eLog(true, 'Site settings saved.');
    } catch (Exception $e) {
        eLog(false, 'Warning: Could not write site settings: ' . $e->getMessage());
    }
}

// 6) Write lock file
if (!$installFailed) {
    @mkdir(dirname($lockFile), 0755, true);
    file_put_contents($lockFile, date('Y-m-d H:i:s'));
    eLog(true, 'Installation complete. Lock file created.');
    unset($_SESSION['vl_install']);
    echo '</div>'; // close install-progress
    echo '<script>window._installDirty=!1;document.getElementById("install-progress").style.display="none";</script>';
    echo '<hr style="margin:1rem 0;border:none;border-top:1px solid rgba(255,255,255,.08);">';
    echo '<div class="success-box"><div class="big-icon">🎉</div>';
    echo '<h2>Installation complete!</h2>';
    echo '<p>Vale has been installed successfully.<br>';
    echo '<p style="margin-bottom:1.5rem;color:var(--text-secondary);">Your site is ready.</p>';
    if ($sampleDataRequested) {
        echo '<div style="margin-top:1.25rem;background:var(--warning-bg);border:1px solid var(--warning-border);border-radius:.5rem;padding:.85rem 1rem;font-size:.85rem;text-align:left;color:var(--warning-text);">';
        echo '<strong>👤 Demo accounts included.</strong> The sample data adds a subscriber login you can use to test the site:<br>';
        echo '<code>user@example.com</code> / <code>password123</code><br>';
        echo '<span style="font-size:.8rem;">These credentials are publicly known — delete the demo users or change their passwords before going live.</span>';
        echo '</div>';
    }
    if ($sessionSecure === 'false') {
        echo '<div style="margin-top:1.25rem;background:var(--warning-bg);border:1px solid var(--warning-border);border-radius:.5rem;padding:.85rem 1rem;font-size:.85rem;text-align:left;color:var(--warning-text);">';
        echo '<strong>⚠️ HTTP site detected.</strong> Your site URL uses HTTP, so <code>SESSION_SECURE</code> is set to <code>false</code> in <code>.env</code>.';
        echo ' If you enable HTTPS later, <strong>set <code>SESSION_SECURE=true</code></strong> in your <code>.env</code> file so session cookies are only sent over HTTPS.';
        echo '</div>';
    }

    echo '<div style="margin-top:1.25rem;text-align:left;font-size:.85rem;color:var(--text-secondary);">';
    echo '<strong style="color:var(--text-primary);">Next steps:</strong>';
    echo '<ul style="margin:.5rem 0 1rem 1.25rem;list-style:disc;">';
    echo '<li>Go to <em>Settings → Mail</em> to configure your email provider and send a test email.</li>';
    echo '<li>Go to <em>Settings → Cron</em> to enable scheduled tasks (newsletters, auto-updates, etc.).</li>';
    echo '<li>Create your first page or post.</li>';
    if ($shopEnabled) {
        echo '<li>Go to <em>Settings → Shop → Payment</em> to configure your payment gateway.</li>';
        echo '<li>Create <em>Terms &amp; Conditions</em>, <em>Return Policy</em> pages under <em>Settings → Shop → Policies</em>.</li>';
        echo '<li>Configure tax rates and shipping options under <em>Settings → Shop → Tax / Shipping</em>.</li>';
        echo '<li>Add your company name, address, VAT/Tax ID, and commercial register info to your store.</li>';
    }
    echo '</ul>';
    echo '<p style="color:var(--text-muted);">You may also need to follow legal regulations and tax rules in your jurisdiction.</p>';
    echo '</div>';

    $envTestUrl = h($appUrl . '/.env');
    echo '<details style="margin-top:1.25rem;text-align:left;"><summary style="cursor:pointer;font-size:.85rem;font-weight:600;color:var(--text-secondary);">🔒 Web Root Protection &amp; Server Security</summary>';
    echo '<div style="margin-top:.75rem;font-size:.85rem;color:var(--text-secondary);text-align:left;">';
    echo 'Point your domain\'s Document Root to the <code>public/</code> directory. This isolates sensitive files like <code>.env</code> and <code>storage/</code> from public access.<br><br>';
    echo '<strong>🔍 Quick Security Check:</strong><br>';
    echo 'Copy and open this URL in your browser: <a href="' . $envTestUrl . '" target="_blank" rel="noopener" style="color:var(--accent-color);word-break:break-all;">' . $envTestUrl . '</a><br>';
    echo '<span style="color:var(--text-muted);font-size:.8rem;">If you see any file content or settings in your browser, your site is publicly exposed! Block access immediately by pointing Document Root to <code>public/</code> or applying the server rules below.</span><br><br>';
    echo 'If your server points to the project root instead, secure manually:<br>';
    echo '<ul style="margin:.4rem 0 0 1.25rem;list-style:disc;">';
    echo '<li><strong>Nginx:</strong> Copy the rewrite rules from <code>nginx.conf</code> into your server block to block access to <code>.env</code>, <code>composer.json</code>, and <code>storage/</code>.</li>';
    echo '<li><strong>Apache:</strong> Ensure <code>AllowOverride All</code> is enabled so the root <code>.htaccess</code> file can protect these files.</li>';
    echo '</ul>';
    echo '</div></details>';

    echo '<a href="' . $scriptDir . '/admin" class="btn" style="display:inline-block;text-decoration:none;margin-top:1.5rem;">Go to Admin Dashboard →</a>';
    echo '</div>';
    exit;
}
?>
    <?php if ($installFailed) : ?>
  <div style="margin-top:1.5rem;color:var(--error-text);font-size:.875rem;">
    Installation stopped due to an error. Fix the issue above and try again.
  </div>
  <a href="install.php?step=1" class="btn btn-sec" style="display:block;text-align:center;text-decoration:none;margin-top:1rem;">
    ← Start Over
  </a>
    <?php endif; ?>

    <?php /* ────────────── STEP 6: Done ────────────── */ ?>
<?php elseif ($step === 6) : ?>
  <div class="success-box">
    <div class="big-icon">🎉</div>
    <h2>Installation complete!</h2>
    <p>Vale has been installed successfully.<br>
    <p style="margin-bottom:1.5rem;color:var(--text-secondary);">Your site is ready.</p>
      <?php if (!empty($wiz['sample_data'])) : ?>
    <div style="margin-top:1.25rem;background:var(--warning-bg);border:1px solid var(--warning-border);border-radius:.5rem;padding:.85rem 1rem;font-size:.85rem;text-align:left;color:var(--warning-text);">
      <strong>👤 Demo accounts included.</strong> The sample data adds a subscriber login you can use to test the site:<br>
      <code>user@example.com</code> / <code>password123</code><br>
      <span style="font-size:.8rem;">These credentials are publicly known — delete the demo users or change their passwords before going live.</span>
    </div>
      <?php endif; ?>
      <?php if ($sessionSecure === 'false') : ?>
    <div style="margin-top:1.25rem;background:var(--warning-bg);border:1px solid var(--warning-border);border-radius:.5rem;padding:.85rem 1rem;font-size:.85rem;text-align:left;color:var(--warning-text);">
      <strong>⚠️ HTTP site detected.</strong> Your site URL uses HTTP, so <code>SESSION_SECURE</code> is set to <code>false</code> in <code>.env</code>.
      If you enable HTTPS later, <strong>set <code>SESSION_SECURE=true</code></strong> in your <code>.env</code> file so session cookies are only sent over HTTPS.
    </div>
      <?php endif; ?>

    <div style="margin-top:1.25rem;text-align:left;font-size:.85rem;color:var(--text-secondary);">
      <strong style="color:var(--text-primary);">Next steps:</strong>
      <ul style="margin:.5rem 0 1rem 1.25rem;list-style:disc;">
        <li>Go to <em>Settings → Mail</em> to configure your email provider and send a test email.</li>
        <li>Go to <em>Settings → Cron</em> to enable scheduled tasks (newsletters, auto-updates, etc.).</li>
        <li>Create your first page or post.</li>
        <?php if ($shopEnabled) : ?>
        <li>Go to <em>Settings → Shop → Payment</em> to configure your payment gateway.</li>
        <li>Create <em>Terms &amp; Conditions</em>, <em>Return Policy</em> pages under <em>Settings → Shop → Policies</em>.</li>
        <li>Configure tax rates and shipping options under <em>Settings → Shop → Tax / Shipping</em>.</li>
        <li>Add your company name, address, VAT/Tax ID, and commercial register info to your store.</li>
        <?php endif; ?>
      </ul>
      <p style="color:var(--text-muted);">You may also need to follow legal regulations and tax rules in your jurisdiction.</p>
    </div>

    <?php $envTestUrl = h(detectAppUrl() . '/.env'); ?>
    <details style="margin-top:1.25rem;text-align:left;"><summary style="cursor:pointer;font-size:.85rem;font-weight:600;color:var(--text-secondary);">🔒 Web Root Protection &amp; Server Security</summary>
    <div style="margin-top:.75rem;font-size:.85rem;color:var(--text-secondary);text-align:left;">
      Point your domain's Document Root to the <code>public/</code> directory. This isolates sensitive files like <code>.env</code> and <code>storage/</code> from public access.<br><br>
      <strong>🔍 Quick Security Check:</strong><br>
      Copy and open this URL in your browser: <a href="<?= $envTestUrl ?>" target="_blank" rel="noopener" style="color:var(--accent-color);word-break:break-all;"><?= $envTestUrl ?></a><br>
      <span style="color:var(--text-muted);font-size:.8rem;">If you see any file content or settings in your browser, your site is publicly exposed! Block access immediately by pointing Document Root to <code>public/</code> or applying the server rules below.</span><br><br>
      If your server points to the project root instead, secure manually:<br>
      <ul style="margin:.4rem 0 0 1.25rem;list-style:disc;">
        <li><strong>Nginx:</strong> Copy the rewrite rules from <code>nginx.conf</code> into your server block to block access to <code>.env</code>, <code>composer.json</code>, and <code>storage/</code>.</li>
        <li><strong>Apache:</strong> Ensure <code>AllowOverride All</code> is enabled so the root <code>.htaccess</code> file can protect these files.</li>
      </ul>
    </div></details>

    <a href="<?= $scriptDir ?>/admin" class="btn" style="display:inline-block;text-decoration:none;">
      Go to Admin Dashboard →
    </a>
  </div>

<?php endif; ?>
</div>
</body>
</html>
