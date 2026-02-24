<?php
declare(strict_types=1);

/**
 * ProstoPDR users_store.php
 *
 * Єдиний формат storage/users.json:
 * {
 *   "users": [
 *     {
 *       "id":"1",
 *       "email":"...",
 *       "name":"...",
 *       "password_hash":"...",
 *       "plan":"free|basic|personal|dev",
 *       "paid_at":null,
 *       "expires_at":null,
 *       "created_at":"2026-02-21T10:00:00+00:00"
 *     }
 *   ]
 * }
 */

function users_store_path(): string {
  return dirname(__DIR__) . '/storage/users.json';
}

/**
 * Завжди повертає ['users' => [...]]
 * Підхоплює старі/криві формати (мапа, список, тощо), але нормалізує на save.
 */
function users_load(): array {
  $path = users_store_path();
  if (!is_file($path)) return ['users' => []];

  $raw = (string)file_get_contents($path);
  if (trim($raw) === '') return ['users' => []];

  $data = json_decode($raw, true);
  if (!is_array($data)) return ['users' => []];

  // Вже правильний формат
  if (isset($data['users']) && is_array($data['users'])) {
    $users = array_values(array_filter($data['users'], 'is_array'));
    return ['users' => $users];
  }

  // Якщо напряму список користувачів
  if (array_is_list($data) && (count($data) === 0 || is_array($data[0]))) {
    return ['users' => array_values(array_filter($data, 'is_array'))];
  }

  // Якщо це мапа id => user
  $isMap = true;
  foreach ($data as $k => $v) {
    if (!is_string($k) && !is_int($k)) { $isMap = false; break; }
    if (!is_array($v)) { $isMap = false; break; }
  }
  if ($isMap) {
    $users = [];
    foreach ($data as $u) $users[] = $u;
    return ['users' => array_values(array_filter($users, 'is_array'))];
  }

  return ['users' => []];
}

/**
 * Атомарний запис, щоб users.json не ламався і не з'являлись "0": {...}
 */
function users_save(array $store): void {
  $path = users_store_path();

  if (!isset($store['users']) || !is_array($store['users'])) {
    $store = ['users' => []];
  }

  // нормалізуємо та чистимо
  $out = [];
  foreach ($store['users'] as $u) {
    if (!is_array($u)) continue;
    $nu = user_normalize($u);
    if ($nu['id'] === '') continue;
    $out[] = $nu;
  }

  $dir = dirname($path);
  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
  }

  $json = json_encode(['users' => array_values($out)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  if ($json === false) {
    throw new RuntimeException('users_save: json_encode failed');
  }

  $tmp = $path . '.tmp';
  $fp = fopen($tmp, 'wb');
  if (!$fp) throw new RuntimeException('users_save: cannot open tmp');

  if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    throw new RuntimeException('users_save: cannot lock tmp');
  }

  fwrite($fp, $json);
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);

  @rename($tmp, $path);
}

function users_all(): array {
  $s = users_load();
  return $s['users'] ?? [];
}

/**
 * ✅ Працює з int або string id (у тебе auth_user_id() повертає int)
 */
function user_find_by_id(int|string $id): ?array {
  $sid = (string)$id;
  foreach (users_all() as $u) {
    if (!is_array($u)) continue;
    if ((string)($u['id'] ?? '') === $sid) return user_normalize($u);
  }
  return null;
}

/**
 * ✅ ОЦЯ ФУНКЦІЯ ТОБІ Й НЕ ВИСТАЧАЛО
 * Оновлює юзера по id. Якщо нема — створює.
 */
function user_update(int|string $id, array $patch): array {
  $sid = (string)$id;

  $store = users_load();
  $users = $store['users'] ?? [];

  $found = false;
  for ($i = 0; $i < count($users); $i++) {
    $u = $users[$i];
    if (!is_array($u)) continue;
    if ((string)($u['id'] ?? '') !== $sid) continue;

    $found = true;
    $users[$i] = user_normalize(array_merge($u, $patch, ['id' => $sid]));
    break;
  }

  if (!$found) {
    $users[] = user_normalize(array_merge([
      'id' => $sid,
      'email' => '',
      'name' => '',
      'password_hash' => '',
      'plan' => 'free',
      'paid_at' => null,
      'expires_at' => null,
      'created_at' => gmdate('c'),
    ], $patch, ['id' => $sid]));
  }

  $store['users'] = array_values($users);
  users_save($store);

  return user_find_by_id($sid) ?? user_normalize(['id' => $sid]);
}

/**
 * Синонім на випадок викликів user_save()
 */
function user_save(array $user): array {
  $id = (string)($user['id'] ?? '');
  if ($id === '') throw new InvalidArgumentException('user_save: empty id');
  return user_update($id, $user);
}

/**
 * Доступ: dev завжди true; basic/personal — якщо expires_at пустий або в майбутньому.
 */
function user_has_access(array $user, ?int $nowTs = null): bool {
  $plan = strtolower((string)($user['plan'] ?? 'free'));
  if ($plan === 'dev') return true;
  if ($plan !== 'basic' && $plan !== 'personal') return false;

  $exp = $user['expires_at'] ?? null;
  if ($exp === null || $exp === '' || $exp === 'null') return true;

  $ts = strtotime((string)$exp);
  if ($ts === false) return true;

  $now = $nowTs ?? time();
  return $ts > $now;
}

/**
 * Нормалізація полів юзера
 */
function user_normalize(array $u): array {
  $u['id'] = (string)($u['id'] ?? '');
  $u['email'] = (string)($u['email'] ?? '');
  $u['name'] = (string)($u['name'] ?? '');
  $u['password_hash'] = (string)($u['password_hash'] ?? '');

  $plan = strtolower(trim((string)($u['plan'] ?? 'free')));
  if ($plan === '' || $plan === 'null' || $plan === 'none') $plan = 'free';
  $u['plan'] = $plan;

  $u['paid_at'] = $u['paid_at'] ?? null;
  $u['expires_at'] = $u['expires_at'] ?? null;

  $u['created_at'] = (string)($u['created_at'] ?? gmdate('c'));

  return $u;
}

/**
 * Ремонт users.json: читає що є, нормалізує і перезаписує в правильному форматі.
 */
function users_repair_and_save(): void {
  $store = users_load();
  users_save($store);
}