<?php

declare(strict_types=1);

function vl_normalize_request_path(): string
{
    $uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $path = is_string($uri) && $uri !== '' ? $uri : '/';

    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptDir = rtrim($scriptDir, '/');
    if ($scriptDir !== '' && $scriptDir !== '/' && str_starts_with($path, $scriptDir)) {
        $path = substr($path, strlen($scriptDir)) ?: '/';
    }

    return '/' . ltrim($path, '/');
}

function vl_respond_not_installed(): never
{
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo "Not installed.\n";
    exit;
}

function vl_handle_not_installed(): never
{
    $path = vl_normalize_request_path();
    if ($path === '/' || $path === '/index.php') {
        $base = rtrim(dirname($_SERVER['PHP_SELF'] ?? ''), '/\\');
        header('Location: ' . $base . '/install.php');
        exit;
    }

    vl_respond_not_installed();
}
