<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

/**
 * Простий .env loader (без бібліотек).
 * Читає файл web-php/.env або web-php/public/.env (якщо раптом там).
 */
(function () {
  $candidates = [
    dirname(__DIR__) . '/.env',          // web-php/.env
    dirname(__DIR__, 2) . '/.env',       // якщо структура інша
    dirname(__DIR__) . '/public/.env',   // web-php/public/.env (на всяк)
  ];

  $envFile = null;
  foreach ($candidates as $p) {
    if (is_file($p)) { $envFile = $p; break; }
  }
  if (!$envFile) return;

  $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if (!$lines) return;

  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) continue;

    $pos = strpos($line, '=');
    if ($pos === false) continue;

    $key = trim(substr($line, 0, $pos));
    $val = trim(substr($line, $pos + 1));

    // прибираємо лапки якщо є
    if ((str_starts_with($val, '"') && str_ends_with($val, '"')) || (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
      $val = substr($val, 1, -1);
    }

    // не перезаписуємо, якщо вже задано в середовищі
    if (getenv($key) === false) {
      putenv($key . '=' . $val);
      $_ENV[$key] = $val;
    }
  }
})();

require_once __DIR__ . '/db.php';

function env(string $key, ?string $default = null): ?string {
  $v = getenv($key);
  if ($v === false || $v === '') return $default;
  return $v;
}

function redirect(string $path): void {
  header('Location: ' . $path);
  exit;
}

function csrf_token(): string {
  if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
  }
  return (string)$_SESSION['csrf'];
}

function csrf_verify(?string $token): void {
  $ok = isset($_SESSION['csrf']) && is_string($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$token);
  if (!$ok) {
    http_response_code(419);
    echo "CSRF token invalid";
    exit;
  }
}

function auth_user_id(): ?int {
  return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

function auth_login(int $userId): void {
  $_SESSION['user_id'] = $userId;
}

function auth_logout(): void {
  unset($_SESSION['user_id']);
}
