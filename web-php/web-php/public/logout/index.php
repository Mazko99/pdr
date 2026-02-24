<?php
declare(strict_types=1);

$bootstrap = __DIR__ . '/../../src/bootstrap.php';
if (is_file($bootstrap)) require_once $bootstrap;

if (session_status() !== PHP_SESSION_ACTIVE) {
  @session_start();
}

// якщо є твоя функція — викликаємо її
if (function_exists('auth_logout')) {
  auth_logout();
} else {
  // fallback: чистимо сесію
  $_SESSION = [];
  if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
      $params["path"], $params["domain"], $params["secure"], $params["httponly"]
    );
  }
  @session_destroy();
}

// редірект на головну
header('Location: /', true, 302);
exit;