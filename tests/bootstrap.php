<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Load global helpers (site_url(), __app_base_path(), etc.)
require_once __DIR__ . '/../app/helpers.php';

// Tests assume a root install regardless of the developer's APP_URL/deployment.
// Prevent a subdirectory base path (e.g. /lightpress) from leaking into URL-sensitive tests.
putenv('APP_URL=http://localhost');
$_ENV['APP_URL']    = 'http://localhost';
$_SERVER['APP_URL'] = 'http://localhost';

if (!defined('VL_DB_PREFIX')) {
    define('VL_DB_PREFIX', 'vl_');
}

// Synthetic route-sweeps (e.g. AdminRouteSmokeTest) intentionally hit routes
// that answer 404 — disable probe throttling so those expected responses
// don't lock the run out. Probe middleware unit tests opt back in via
// enabled: true.
putenv('VLE_SKIP_RATE_LIMIT=1');
$_ENV['VLE_SKIP_RATE_LIMIT']    = '1';
$_SERVER['VLE_SKIP_RATE_LIMIT'] = '1';
