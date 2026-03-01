<?php
declare(strict_types=1);

$action = (string)($_GET['a'] ?? '');
if ($action === '') {
  // сумісність: якщо хтось відкрив /pay/ без a=
  $action = 'checkout';
}

$baseDir = __DIR__;

switch ($action) {
  case 'ping':
    require $baseDir . '/ping.php';
    exit;

  case 'checkout':
  default:
    require $baseDir . '/mono_checkout.php';
    exit;
}