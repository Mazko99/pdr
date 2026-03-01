<?php
declare(strict_types=1);

/**
 * Front router for built-in PHP server (php -S).
 * Must be used as: php -S 0.0.0.0:$PORT -t public public/router.php
 */

$uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
$path = parse_url($uri, PHP_URL_PATH);
$path = is_string($path) ? $path : '/';

$publicDir = __DIR__;
$clean = '/' . ltrim($path, '/');

// 1) If file exists in /public -> serve it directly
$full = realpath($publicDir . $clean);
if ($full !== false) {
  $pubReal = realpath($publicDir);
  if ($pubReal !== false && str_starts_with($full, $pubReal) && is_file($full)) {
    return false; // let PHP serve static file
  }
}

// 2) Directory -> try index.php inside it
if (str_ends_with($clean, '/')) {
  $idx = $publicDir . rtrim($clean, '/') . '/index.php';
  if (is_file($idx)) {
    require $idx;
    exit;
  }
}

// 3) If URL without ".php" points to folder with index.php
$maybeDirIndex = $publicDir . $clean . '/index.php';
if (is_file($maybeDirIndex)) {
  require $maybeDirIndex;
  exit;
}

// 4) If URL without ".php" matches "/xxx" and "/xxx.php" exists
$maybePhp = $publicDir . $clean . '.php';
if (is_file($maybePhp)) {
  require $maybePhp;
  exit;
}

// 5) Special: /pay should go to /pay/index.php even if missing trailing slash
if ($clean === '/pay') {
  $payIndex = $publicDir . '/pay/index.php';
  if (is_file($payIndex)) {
    require $payIndex;
    exit;
  }
}

// 6) Fallback -> main site index.php
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