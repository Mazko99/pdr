<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
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

function auth_user_id(): ?string {
  return isset($_SESSION['user_id']) ? (string)$_SESSION['user_id'] : null;
}

function auth_login(string $userId): void {
  $_SESSION['user_id'] = $userId;
}

function auth_logout(): void {
  unset($_SESSION['user_id']);
}
