<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
  @session_start();
}

/**
 * HTML escape helper for admin
 */
if (!function_exists('admin_h')) {
  function admin_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

/**
 * Admin CSRF (окремо від user csrf)
 */
if (!function_exists('admin_csrf_token')) {
  function admin_csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    if (empty($_SESSION['admin_csrf']) || !is_string($_SESSION['admin_csrf'])) {
      $_SESSION['admin_csrf'] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['admin_csrf'];
  }
}

if (!function_exists('admin_csrf_verify')) {
  function admin_csrf_verify(?string $token): void {
    $token = (string)($token ?? '');
    $real = (string)($_SESSION['admin_csrf'] ?? '');
    if ($real === '' || $token === '' || !hash_equals($real, $token)) {
      http_response_code(400);
      echo 'CSRF token invalid';
      exit;
    }
  }
}

/**
 * Read ADMIN_KEY from env or .env
 */
if (!function_exists('admin_read_env_file_value')) {
  function admin_read_env_file_value(string $key, string $envPath): string {
    if (!is_file($envPath)) return '';
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) return '';

    foreach ($lines as $line) {
      $line = trim((string)$line);
      if ($line === '' || str_starts_with($line, '#')) continue;

      $pos = strpos($line, '=');
      if ($pos === false) continue;

      $k = trim(substr($line, 0, $pos));
      if ($k !== $key) continue;

      $v = trim(substr($line, $pos + 1));
      if (strlen($v) >= 2) {
        $first = $v[0];
        $last = $v[strlen($v) - 1];
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
          $v = substr($v, 1, -1);
        }
      }
      return trim($v);
    }
    return '';
  }
}

if (!function_exists('admin_get_admin_key')) {
  function admin_get_admin_key(): string {
    $v = getenv('ADMIN_KEY');
    if (is_string($v) && trim($v) !== '') {
      $v = trim($v);
      if (strlen($v) >= 2) {
        $first = $v[0];
        $last = $v[strlen($v) - 1];
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
          $v = substr($v, 1, -1);
        }
      }
      return trim($v);
    }

    $envPath = __DIR__ . '/../../.env';
    $fromFile = admin_read_env_file_value('ADMIN_KEY', $envPath);
    if ($fromFile !== '') return $fromFile;

    return '';
  }
}

/**
 * Require admin auth (redirect to login)
 */
if (!function_exists('admin_require')) {
  function admin_require(): void {
    if (empty($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
      header('Location: /admin/login.php', true, 302);
      exit;
    }
  }
}

admin_require();