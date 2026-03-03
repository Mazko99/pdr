<?php
declare(strict_types=1);

/**
 * Front router for built-in PHP server (php -S).
 * Must be used as:
 * php -S 0.0.0.0:$PORT -t public public/router.php
 */

$uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
$path = parse_url($uri, PHP_URL_PATH);
$path = is_string($path) ? $path : '/';

$publicDir = __DIR__;
$clean = '/' . ltrim($path, '/');

// ---- Security: block path traversal ----
if (str_contains($clean, "\0") || str_contains($clean, '..')) {
  http_response_code(400);
  header('Content-Type: text/plain; charset=utf-8');
  echo "400 bad path\n";
  exit;
}

/**
 * ✅ FIX for relative asset paths:
 * If some page uses "assets/..." (without leading slash),
 * browser requests "/account/assets/..." or "/pay/assets/..."
 * We'll map "/{any}/assets/..." -> "/assets/..."
 */
if (preg_match('#^/[^/]+/(assets/.*)$#', $clean, $m)) {
  $clean = '/' . $m[1];
}

/**
 * 1) If exact file exists in /public:
 *    - static files: let PHP serve them directly (return false)
 *    - php files: execute via require
 */
$direct = $publicDir . $clean;
if ($clean !== '/' && is_file($direct)) {
  // If it's a PHP script -> run it
  if (str_ends_with($direct, '.php')) {
    require $direct;
    exit;
  }
  // Otherwise it's static -> let server handle
  return false;
}

/**
 * 2) Directory -> try index.php inside it
 */
if (str_ends_with($clean, '/')) {
  $idx = $publicDir . rtrim($clean, '/') . '/index.php';
  if (is_file($idx)) {
    require $idx;
    exit;
  }
}

/**
 * 3) If URL without ".php" points to folder with index.php
 */
$maybeDirIndex = $publicDir . $clean . '/index.php';
if (is_file($maybeDirIndex)) {
  require $maybeDirIndex;
  exit;
}

/**
 * 4) If URL without ".php" matches "/xxx" and "/xxx.php" exists
 */
$maybePhp = $publicDir . $clean . '.php';
if (is_file($maybePhp)) {
  require $maybePhp;
  exit;
}

/**
 * 5) Special: /pay should go to /pay/index.php even if missing trailing slash
 */
if ($clean === '/pay') {
  $payIndex = $publicDir . '/pay/index.php';
  if (is_file($payIndex)) {
    require $payIndex;
    exit;
  }
}

/**
 * 6) Fallback -> main site index.php
 */
$main = $publicDir . '/index.php';
if (is_file($main)) {
  require $main;
  exit;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "404 not found\n";
echo "URI={$uri}\n";
echo "PATH={$clean}\n";