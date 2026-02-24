<?php
declare(strict_types=1);

$uri  = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH) ?: '/';
$path = '/' . ltrim($path, '/');
$path = preg_replace('#/+#', '/', $path);

$full = __DIR__ . $path;

// 1) Статика / реальні файли
if ($path !== '/' && is_file($full)) {
  return false;
}

// 2) Якщо /login або /login/ — відкриваємо /login/index.php
$index1 = __DIR__ . rtrim($path, '/') . '/index.php';
if ($path !== '/' && is_file($index1)) {
  require $index1;
  exit;
}

// 3) Якщо запит /terms — а є terms.php
$php = __DIR__ . $path . '.php';
if ($path !== '/' && is_file($php)) {
  require $php;
  exit;
}

// 4) Все інше — головний index
require __DIR__ . '/index.php';