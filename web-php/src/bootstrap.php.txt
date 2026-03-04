<?php
declare(strict_types=1);

/**
 * ProstoPDR bootstrap.php
 * - Loads .env BEFORE session_start (so COOKIE_DOMAIN works)
 * - Starts one consistent session for www and non-www
 * - Provides helpers: env(), redirect(), csrf_*, auth_*
 * - Includes device_sessions.php once (if exists)
 */

// -------------------- .env loader (NO libs) --------------------
(function () {
  $candidates = [
    dirname(__DIR__) . '/.env',          // web-php/.env
    dirname(__DIR__, 2) . '/.env',       // fallback if structure differs
    dirname(__DIR__) . '/public/.env',   // optional
  ];

  $envFile = null;
  foreach ($candidates as $p) {
    if (is_file($p)) { $envFile = $p; break; }
  }
  if (!$envFile) return;

  $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if (!$lines) return;

  foreach ($lines as $line) {
    $line = trim((string)$line);
    if ($line === '' || str_starts_with($line, '#')) continue;

    $pos = strpos($line, '=');
    if ($pos === false) continue;

    $key = trim(substr($line, 0, $pos));
    $val = trim(substr($line, $pos + 1));

    // strip quotes
    if (
      (str_starts_with($val, '"') && str_ends_with($val, '"')) ||
      (str_starts_with($val, "'") && str_ends_with($val, "'"))
    ) {
      $val = substr($val, 1, -1);
    }

    if (getenv($key) === false) {
      putenv($key . '=' . $val);
      $_ENV[$key] = $val;
    }
  }
})();

// -------------------- Session (shared for www/non-www) --------------------
if (session_status() !== PHP_SESSION_ACTIVE) {
  $cookieDomain = (string)(getenv('COOKIE_DOMAIN') ?: '');

  $isHttps =
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

  $params = [
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => $isHttps,
  ];

  // domain only if provided
  if ($cookieDomain !== '') {
    $params['domain'] = $cookieDomain; // e.g. .prostopdr.com
  }

  session_set_cookie_params($params);
  session_start();
}

require_once __DIR__ . '/db.php';

function db(): PDO {

    static $pdo = null;

    if ($pdo) return $pdo;

    $url = getenv('DATABASE_URL');

    if (!$url) {
        throw new Exception('DATABASE_URL not set');
    }

    $parts = parse_url($url);

    $host = $parts['host'];
    $port = $parts['port'];
    $user = $parts['user'];
    $pass = $parts['pass'];
    $db   = ltrim($parts['path'], '/');

    $dsn = "pgsql:host=$host;port=$port;dbname=$db";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    return $pdo;
}
// -------------------- Helpers --------------------
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

// -------------------- Auth --------------------
function auth_user_id(): ?string {
  $id = $_SESSION['user_id'] ?? null;
  if (!is_string($id) || $id === '') return null;
  return $id;
}

function auth_login(string $userId): void {
  $_SESSION['user_id'] = $userId;
}

function auth_logout(): void {
  unset($_SESSION['user_id'], $_SESSION['has_access'], $_SESSION['plan']);
}

// -------------------- Device policy include (once) --------------------
$dsFile = __DIR__ . '/device_sessions.php';
if (is_file($dsFile)) {
  require_once $dsFile;
}

function auth_enforce_device_policy(): void {
  $uid = auth_user_id();
  if (!$uid) return;

  if (!function_exists('ds_is_session_active')) return;

  $sid = session_id();
  if ($sid === '') return;

  if (!ds_is_session_active($uid, $sid)) {
    auth_logout();
    redirect('/login?reason=another_device');
  }
}
function ppdr_storage_dir(): string {
  // 1) Railway volume path (ти задаєш в Variables)
  $dir = (string)getenv('PPDR_STORAGE_DIR');
  if ($dir !== '') return rtrim($dir, '/\\');

  // 2) fallback: public/storage (локально)
  return dirname(__DIR__) . '/public/storage';
}

function progress_path(): string {
  return ppdr_storage_dir() . '/progress.json';
}

function progress_load(): array {
  $p = progress_path();

  if (!is_file($p)) return ['users' => []];
  $raw = file_get_contents($p);
  if (!is_string($raw) || $raw === '') return ['users' => []];

  if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);

  $data = json_decode($raw, true);
  if (!is_array($data)) return ['users' => []];
  if (!isset($data['users']) || !is_array($data['users'])) $data['users'] = [];
  return $data;
}

function progress_save(array $data): void {
  $p = progress_path();
  $dir = dirname($p);

  if (!is_dir($dir)) @mkdir($dir, 0775, true);

  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  if (!is_string($json)) $json = '{"users":{}}';

  file_put_contents($p, $json, LOCK_EX);
}
/**
 * Recalculate access from users_store (call AFTER requiring users_store.php)
 */
function auth_refresh_access(): void {
  if (!function_exists('user_find_by_id') || !function_exists('user_has_access')) {
    return;
  }

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