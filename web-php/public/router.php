<?php
declare(strict_types=1);

/**
 * Router for PHP built-in server (php -S ... public/router.php)
 * - Serves existing files directly
 * - Routes /pay and /pay/* to /public/pay/index.php (or file if exists)
 * - Routes everything else to /public/index.php
 */

$uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
$path = (string)parse_url($uri, PHP_URL_PATH);
if ($path === '') $path = '/';

$publicDir = __DIR__;

// 1) If requested path is an existing file -> let PHP server serve it
$full = realpath($publicDir . $path);
$pubReal = realpath($publicDir);

if ($full !== false && $pubReal !== false && str_starts_with($full, $pubReal) && is_file($full)) {
  return false; // serve as static / direct PHP file (ping.php, assets, etc.)
}

// 2) PAY routing
if ($path === '/pay' || str_starts_with($path, '/pay/')) {
  // if user requested /pay/somefile.php and it exists -> serve it
  $candidate = $publicDir . $path;
  if (is_file($candidate)) {
    require $candidate;
    exit;
  }

  // if user requested /pay or /pay/ -> run pay/index.php
  $payIndex = $publicDir . '/pay/index.php';
  if (is_file($payIndex)) {
    require $payIndex;
    exit;
  }

  http_response_code(404);
  header('Content-Type: text/plain; charset=utf-8');
  echo "pay/index.php not found\n";
  exit;
}

// 3) Default app entry
$index = $publicDir . '/index.php';
if (is_file($index)) {
  require $index;
  exit;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "public/index.php not found\n";
exit;