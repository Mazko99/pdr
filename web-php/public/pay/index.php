<?php
declare(strict_types=1);

/**
 * FIX: allow direct access to /pay/* and existing files/dirs
 * Put this at the VERY TOP of /public/index.php (first code in file).
 */

$__uriPath = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

// 1) Пропускаємо оплату: /pay/... -> /public/pay/index.php
if (strpos($__uriPath, '/pay') === 0) {
  $payIndex = __DIR__ . '/pay/index.php';
  if (is_file($payIndex)) {
    require $payIndex;
    exit;
  }
  // якщо раптом файлу нема — покажемо помилку, а не головну
  http_response_code(404);
  echo 'pay/index.php not found';
  exit;
}

// 2) Пропускаємо існуючі файли/папки (css/js/img і тд)
// щоб роутер не зʼїдав assets
$__full = realpath(__DIR__ . $__uriPath);
$__root = realpath(__DIR__);

if ($__full !== false && $__root !== false) {
  // важливо: перевірка що файл всередині /public
  if (strpos($__full, $__root) === 0 && is_file($__full)) {
    // Віддаємо файл напряму
    $ext = strtolower(pathinfo($__full, PATHINFO_EXTENSION));

    // мінімальні content-type
    $types = [
      'css'  => 'text/css; charset=utf-8',
      'js'   => 'application/javascript; charset=utf-8',
      'png'  => 'image/png',
      'jpg'  => 'image/jpeg',
      'jpeg' => 'image/jpeg',
      'gif'  => 'image/gif',
      'svg'  => 'image/svg+xml',
      'webp' => 'image/webp',
      'ico'  => 'image/x-icon',
      'woff' => 'font/woff',
      'woff2'=> 'font/woff2',
      'ttf'  => 'font/ttf',
      'json' => 'application/json; charset=utf-8',
    ];

    if (isset($types[$ext])) {
      header('Content-Type: ' . $types[$ext]);
    }
    readfile($__full);
    exit;
  }
}

// ⬇️ ДАЛІ ЙДЕ ТВОЯ ІСНУЮЧА ЛОГІКА index.php (нічого більше не міняй)