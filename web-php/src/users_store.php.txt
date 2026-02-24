<?php
declare(strict_types=1);

/**
 * users_store.php
 *
 * Єдиний формат storage/users.json:
 * { "users": [ {user}, ... ] }
 *
 * id — STRING (uuid/hex).
 */

function users_store_path(): string {
  return dirname(__DIR__) . '/storage/users.json';
}

function users_load(): array {
  $path = users_store_path();
  if (!is_file($path)) return ['users' => []];

  $raw = (string)file_get_contents($path);
  if (trim($raw) === '') return ['users' => []];

  $data = json_decode($raw, true);
  if (!is_array($data)) return ['users' => []];

  if (isset($data['users']) && is_array($data['users'])) {
    return ['users' => array_values(array_filter($data['users'], 'is_array'))];
  }

  if (array_is_list($data) && (count($data) === 0 || is_array($data[0]))) {
    return ['users' => array_values(array_filter($data, 'is_array'))];
  }

  // map id => user
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

function users_save(array $store): void {
  $path = users_store_path();

  if (!isset($store['users']) || !is_array($store['users'])) {
    $store = ['users' => []];
  }

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

function user_find_by_id(string $id): ?array {
  $id = (string)$id;
  if ($id === '') return null;

  foreach (users_all() as $u) {
    if (!is_array($u)) continue;
    if ((string)($u['id'] ?? '') === $id) return user_normalize($u);
  }
  return null;
}

function user_find_by_email(string $email): ?array {
  $email = strtolower(trim($email));
  if ($email === '') return null;

  foreach (users_all() as $u) {
    if (!is_array($u)) continue;
    $ue = strtolower(trim((string)($u['email'] ?? '')));
    if ($ue !== '' && $ue === $email) return user_normalize($u);
  }
  return null;
}

/**
 * ✅ Потрібно для login:
 */
function user_verify_password(array $user, string $password): bool {
  $hash = (string)($user['password_hash'] ?? '');
  if ($hash === '') return false;
  return password_verify($password, $hash);
}

/**
 * ✅ Твій виклик: user_create($email, $name, $hash)
 * Повертає STRING id (uuid/hex)
 */
function user_create(string $email, string $name, string $passwordHash): string {
  $email = strtolower(trim($email));
  $name = trim($name);

  $existing = user_find_by_email($email);
  if ($existing) {
    return (string)$existing['id'];
  }

  $store = users_load();
  $users = $store['users'] ?? [];

  // генеруємо id, який точно не конфліктує
  do {
    $newId = bin2hex(random_bytes(16));
  } while (user_find_by_id($newId) !== null);

  $user = user_normalize([
    'id' => $newId,
    'email' => $email,
    'name' => $name,
    'password_hash' => $passwordHash,
    'plan' => 'free',
    'paid_at' => null,
    'expires_at' => null,
    'created_at' => gmdate('c'),
  ]);

  $users[] = $user;
  $store['users'] = array_values($users);
  users_save($store);

  return $newId;
}

function user_update(string $id, array $patch): array {
  $id = (string)$id;

  $store = users_load();
  $users = $store['users'] ?? [];

  $found = false;
  for ($i = 0; $i < count($users); $i++) {
    $u = $users[$i];
    if (!is_array($u)) continue;
    if ((string)($u['id'] ?? '') !== $id) continue;

    $found = true;
    $users[$i] = user_normalize(array_merge($u, $patch, ['id' => $id]));
    break;
  }

  if (!$found) {
    $users[] = user_normalize(array_merge([
      'id' => $id,
      'email' => '',
      'name' => '',
      'password_hash' => '',
      'plan' => 'free',
      'paid_at' => null,
      'expires_at' => null,
      'created_at' => gmdate('c'),
    ], $patch, ['id' => $id]));
  }

  $store['users'] = array_values($users);
  users_save($store);

  return user_find_by_id($id) ?? user_normalize(['id' => $id]);
}

function user_save(array $user): array {
  $id = (string)($user['id'] ?? '');
  if ($id === '') throw new InvalidArgumentException('user_save: empty id');
  return user_update($id, $user);
}

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

function users_repair_and_save(): void {
  $store = users_load();
  users_save($store);
}