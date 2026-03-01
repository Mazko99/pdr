<?php
declare(strict_types=1);

// ВАЖЛИВО: цей router.php запускається як:
// php -S 0.0.0.0:$PORT -t public public/router.php

$uriPath = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if ($uriPath === '') $uriPath = '/';

$publicDir = __DIR__;
$fullPath = realpath($publicDir . $uriPath);

// 1) якщо це існуючий файл всередині public — віддай як файл (php включно)
if ($fullPath !== false) {
  $publicReal = realpath($publicDir);
  if ($publicReal !== false && str_starts_with($fullPath, $publicReal)) {

    if (is_file($fullPath)) {
      $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
      if ($ext === 'php') {
        require $fullPath;
      } else {
        return false; // нехай built-in server віддасть статику
      }
      exit;
    }

    // 2) якщо це директорія — пробуй index.php
    if (is_dir($fullPath)) {
      $indexPhp = rtrim($fullPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'index.php';
      if (is_file($indexPhp)) {
        require $indexPhp;
        exit;
      }
    }
  }
}

// 3) спеціально: /pay та /pay/ → /pay/index.php
if ($uriPath === '/pay' || $uriPath === '/pay/') {
  $payIndex = $publicDir . '/pay/index.php';
  if (is_file($payIndex)) {
    require $payIndex;
    exit;
  }
  http_response_code(404);
  echo "pay/index.php not found";
  exit;
}

// 4) дефолт: головна index.php (або 404)
$rootIndex = $publicDir . '/index.php';
if (is_file($rootIndex)) {
  require $rootIndex;
  exit;
}

http_response_code(404);
echo "index.php not found";