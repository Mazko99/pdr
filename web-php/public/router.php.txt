<?php
declare(strict_types=1);

$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$fullPath = __DIR__ . $uriPath;

// Віддати статичні файли напряму
if ($uriPath !== '/' && is_file($fullPath)) {
  return false;
}

// Все інше — в index.php (твій “контролер”)
require __DIR__ . '/index.php';