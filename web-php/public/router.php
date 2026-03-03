<?php
declare(strict_types=1);

/**
 * Router for PHP built-in server (Railway).
 * Start with:
 * php -S 0.0.0.0:$PORT -t public public/router.php
 */

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH);
$path = is_string($path) ? $path : '/';

$publicDir = __DIR__;
$filePath = $publicDir . $path;

// If exact file exists inside /public → execute it
if (is_file($filePath)) {
    require $filePath;
    exit;
}

// If directory → try index.php inside
if (is_dir($filePath)) {
    $indexFile = rtrim($filePath, '/') . '/index.php';
    if (is_file($indexFile)) {
        require $indexFile;
        exit;
    }
}

// If "/xxx" and "/xxx.php" exists
if (is_file($publicDir . $path . '.php')) {
    require $publicDir . $path . '.php';
    exit;
}

// Fallback → main index.php
require $publicDir . '/index.php';
exit;