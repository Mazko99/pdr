<?php
declare(strict_types=1);

/**
 * Front router for built-in PHP server (php -S).
 * Must be used as: php -S 0.0.0.0:$PORT -t public public/router.php
 */

$uri  = (string)($_SERVER['REQUEST_URI'] ?? '/');
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
 * browser can request "/account/assets/..." when HTML uses "assets/..." (no leading slash)
 * map "/{any}/assets/..." -> "/assets/..."
 */
if (preg_match('#^/[^/]+/(assets/.*)$#', $clean, $m)) {
  $clean = '/' . $m[1];
}

// 1) direct existing file in /public
$direct = $publicDir . $clean;
if ($clean !== '/' && is_file($direct)) {
  // Execute PHP scripts
  if (str_ends_with($direct, '.php')) {
    require $direct;
    exit;
  }
  // Serve static
  return false;
}

// 2) Directory -> index.php
if (str_ends_with($clean, '/')) {
  $idx = $publicDir . rtrim($clean, '/') . '/index.php';
  if (is_file($idx)) {
    require $idx;
    exit;
  }
}

// 3) URL without trailing slash points to folder with index.php
$maybeDirIndex = $publicDir . $clean . '/index.php';
if (is_file($maybeDirIndex)) {
  require $maybeDirIndex;
  exit;
}

// 4) URL "/xxx" -> "/xxx.php"
$maybePhp = $publicDir . $clean . '.php';
if (is_file($maybePhp)) {
  require $maybePhp;
  exit;
}

// 5) Special: /pay -> /pay/index.php
if ($clean === '/pay') {
  $payIndex = $publicDir . '/pay/index.php';
  if (is_file($payIndex)) {
    require $payIndex;
    exit;
  }
}

// 6) Fallback -> /index.php
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