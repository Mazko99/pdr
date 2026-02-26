<?php
declare(strict_types=1);

$bootstrap = __DIR__ . '/../../src/bootstrap.php';
$usersStore = __DIR__ . '/../../src/users_store.php';

if (is_file($bootstrap)) require_once $bootstrap;
if (is_file($usersStore)) require_once $usersStore;

if (session_status() !== PHP_SESSION_ACTIVE) {
  @session_start();
}

function admin_env_str(string $key, string $default = ''): string {
  $v = getenv($key);
  if ($v === false || $v === null || $v === '') return $default;
  return (string)$v;
}

function admin_require(): void {
  // Якщо є окрема адмін-автентифікація — перевіряємо її.
  // Підтримка декількох варіантів: admin_logged / is_admin / admin_id / admin_email
  $ok =
    (!empty($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true)
    || (!empty($_SESSION['is_admin']) && $_SESSION['is_admin'] === true)
    || (!empty($_SESSION['admin_id']))
    || (!empty($_SESSION['admin_email']));

  // Також дозволимо простий .env пароль (якщо ти так робив)
  // ADMIN_PASS або ADMIN_PASSWORD — якщо в тебе є сторінка входу в адмінку.
  // Тут нічого не “логінимо”, лише перевіряємо що сесія адміна встановлена.
  if (!$ok) {
    http_response_code(403);
    echo "Access denied (admin).";
    exit;
  }
}

admin_require();

/**
 * Адмін CSRF токен
 */
function admin_csrf_token(): string {
  if (empty($_SESSION['admin_csrf']) || !is_string($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(16));
  }
  return (string)$_SESSION['admin_csrf'];
}

function admin_csrf_verify(?string $token): void {
  $sess = (string)($_SESSION['admin_csrf'] ?? '');
  $tok = (string)$token;
  if ($sess === '' || $tok === '' || !hash_equals($sess, $tok)) {
    http_response_code(419);
    echo "CSRF error.";
    exit;
  }
}

function admin_h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function admin_is_assoc(array $arr): bool {
  $keys = array_keys($arr);
  return array_keys($keys) !== $keys;
}

function admin_users_json_path(): string {
  return __DIR__ . '/../../storage/users.json';
}

/**
 * Читання users.json.
 * Підтримує:
 * 1) { "users": [ ... ] }
 * 2) [ ... ]
 * 3) { "1": {...}, "2": {...} }
 *
 * Повертає LIST користувачів (масив масивів).
 */
function admin_users_load_list(): array {
  $path = admin_users_json_path();
  if (!is_file($path)) return [];

  $raw = file_get_contents($path);
  if ($raw === false) return [];
  if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);

  $data = json_decode($raw, true);
  if (!is_array($data)) return [];

  if (isset($data['users']) && is_array($data['users'])) {
    return array_values(array_filter($data['users'], 'is_array'));
  }

  // list
  if (array_is_list($data)) {
    return array_values(array_filter($data, 'is_array'));
  }

  // map
  $out = [];
  foreach ($data as $k => $u) {
    if (is_array($u)) $out[] = $u;
  }
  return $out;
}

/**
 * Повертає MAP id => user
 */
function admin_users_load_map(): array {
  $list = admin_users_load_list();
  $map = [];
  foreach ($list as $u) {
    if (!is_array($u)) continue;
    $id = (string)($u['id'] ?? '');
    if ($id === '') continue;
    $map[$id] = $u;
  }
  return $map;
}

/**
 * Запис users.json строго у форматі { "users": [ ... ] }
 */
function admin_users_save_map(array $map): void {
  $list = [];
  foreach ($map as $id => $u) {
    if (!is_array($u)) continue;
    $u['id'] = (string)($u['id'] ?? $id);
    if ($u['id'] === '') continue;
    $list[] = $u;
  }

  $path = admin_users_json_path();
  $dir = dirname($path);
  if (!is_dir($dir)) @mkdir($dir, 0775, true);

  $json = json_encode(['users' => array_values($list)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  if ($json === false) throw new RuntimeException('admin_users_save_map: json_encode failed');

  $tmp = $path . '.tmp';
  $fp = fopen($tmp, 'wb');
  if (!$fp) throw new RuntimeException('admin_users_save_map: cannot open tmp');
  if (!flock($fp, LOCK_EX)) { fclose($fp); throw new RuntimeException('admin_users_save_map: cannot lock tmp'); }

  fwrite($fp, $json);
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);

  @rename($tmp, $path);
}

function admin_fmt_dt($s): string {
  $s = (string)$s;
  if ($s === '' || $s === 'null') return '—';
  if (ctype_digit($s)) {
    return date('Y-m-d H:i', (int)$s);
  }
  return $s;
}

function admin_user_registered_at(array $u): string {
  return (string)($u['created_at'] ?? $u['registered_at'] ?? $u['reg_at'] ?? '—');
}

function admin_user_paid_at(array $u): string {
  return (string)($u['paid_at'] ?? $u['payment_date'] ?? $u['last_payment_at'] ?? '—');
}

function admin_user_expires_at(array $u): string {
  return (string)($u['subscription_until'] ?? $u['expires_at'] ?? $u['plan_until'] ?? '—');
}