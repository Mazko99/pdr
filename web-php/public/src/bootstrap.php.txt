<?php
declare(strict_types=1);

/**
 * ВАЖЛИВО: session cookie на весь сайт, інакше бувають редірект-лупи
 * (коли /account бачить одну сесію, /login іншу, або cookie не ставиться).
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
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
require_once __DIR__ . '/users_store.php';

/**
 * ✅ ПІДКЛЮЧЕННЯ: ліміт 2 пристрої + 1 активна сесія
 */
require_once __DIR__ . '/device_sessions.php';

function env(string $key, ?string $default = null): ?string {
  $v = getenv($key);
  if ($v === false || $v === '') return $default;
  return $v;
}

function redirect(string $path): void {
  header('Location: ' . $path, true, 302);
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

/**
 * ✅ ВАЖЛИВО:
 * У тебе user_id може бути UUID/хеш (string), тому тримаємо ID як string.
 */
function auth_user_id(): ?string {
  if (!isset($_SESSION['user_id'])) return null;
  $v = $_SESSION['user_id'];
  if (is_int($v)) return (string)$v;
  if (is_string($v) && $v !== '') return $v;
  return null;
}

/**
 * ✅ Генерує токен сесії (унікальний для кожного логіну),
 * щоб можна було зробити "лише 1 активний вхід".
 */
function auth_ensure_session_token(): string {
  if (empty($_SESSION['session_token']) || !is_string($_SESSION['session_token'])) {
    $_SESSION['session_token'] = bin2hex(random_bytes(24));
  }
  return (string)$_SESSION['session_token'];
}

function auth_login(int|string $userId): void {
  // зберігаємо як string, щоб не ламати UUID/хеші
  $_SESSION['user_id'] = (string)$userId;

  // ✅ 1 активний вхід + максимум 2 пристрої
  $token = auth_ensure_session_token();
  $res = ds_on_login((string)$userId, $token, 2);

  if (isset($res['ok']) && $res['ok'] === false && ($res['error'] ?? '') === 'MAX_DEVICES') {
    // якщо 3-й пристрій — блокуємо вхід
    auth_logout();
    redirect('/login?reason=max_devices');
  }

  auth_refresh_access();
}

function auth_logout(): void {
  $uid = auth_user_id();

  // ✅ прибираємо активну сесію, якщо це саме вона
  if ($uid && !empty($_SESSION['session_token']) && is_string($_SESSION['session_token'])) {
    ds_on_logout((string)$uid, (string)$_SESSION['session_token']);
  }

  unset($_SESSION['user_id']);
  unset($_SESSION['has_access'], $_SESSION['plan']);
  unset($_SESSION['session_token']);
}

/**
 * ✅ Перерахунок доступу з users.json, щоб не ловити лупи “план поставив — доступу нема”.
 */
function auth_refresh_access(): void {
  $uid = auth_user_id();
  if (!$uid) {
    $_SESSION['has_access'] = false;
    $_SESSION['plan'] = 'free';
    return;
  }

  $user = user_find_by_id($uid);
  if (!$user) {
    $_SESSION['has_access'] = false;
    $_SESSION['plan'] = 'free';
    return;
  }

  $_SESSION['plan'] = (string)($user['plan'] ?? 'free');
  $_SESSION['has_access'] = user_has_access($user);
}

/**
 * ✅ Примусова "1 активна сесія".
 * Якщо користувач увійшов на іншому пристрої — цей сеанс вибиває.
 *
 * ВАЖЛИВО: не перевіряємо це на сторінках /login /register, щоб не зробити лупи.
 */
(function () {
  $uid = auth_user_id();
  if (!$uid) return;

  // якщо токена немає (старі сесії) — створюємо, але НЕ робимо login-подію
  $token = auth_ensure_session_token();

  $path = (string)($_SERVER['SCRIPT_NAME'] ?? '');
  $uri  = (string)($_SERVER['REQUEST_URI'] ?? '');

  // allowlist: де не треба вибивати/редіректити
  $skip = false;
  foreach (['/login', '/register', '/logout'] as $s) {
    if (str_contains($path, $s) || str_contains($uri, $s)) { $skip = true; break; }
  }
  if ($skip) return;

  // якщо цей токен НЕ активний — вибиваємо
  if (!ds_is_session_active((string)$uid, $token)) {
    // чистимо сесію повністю
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
      session_destroy();
    }
    redirect('/login?reason=another_device');
  }
})();