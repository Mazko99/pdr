<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$full = __DIR__ . $path;

// Якщо це реальний файл (css/js/png/svg/woff2 тощо) — віддай його напряму
if ($path !== '/' && is_file($full)) {
  return false;
}

// Інакше — все ведемо в головний index.php
require __DIR__ . '/index.php';