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

/* ============================================================
   ✅ СЕСІЇ ПРИСТРОЇВ (для “скидати активні сеанси”)
   storage/sessions.json:
   {
     "users": {
       "1": {
         "sessions": {
           "PHPSESSID...": { "sid":"...", "ip":"", "ua":"", "created_at":"", "last_seen":"", "label":"" }
         },
         "revoked": { "sid1": "2026-02-25T..." }
       }
     }
   }
============================================================ */

function sessions_store_path(): string {
  return dirname(__DIR__) . '/storage/sessions.json';
}

function sessions_load(): array {
  $p = sessions_store_path();
  if (!is_file($p)) return ['users' => []];
  $raw = (string)file_get_contents($p);
  if (trim($raw) === '') return ['users' => []];
  $data = json_decode($raw, true);
  if (!is_array($data)) return ['users' => []];
  if (!isset($data['users']) || !is_array($data['users'])) $data['users'] = [];
  return $data;
}

function sessions_save(array $data): void {
  if (!isset($data['users']) || !is_array($data['users'])) $data['users'] = [];
  $p = sessions_store_path();
  $dir = dirname($p);
  if (!is_dir($dir)) @mkdir($dir, 0775, true);

  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  if ($json === false) throw new RuntimeException('sessions_save: json_encode failed');

  $tmp = $p . '.tmp';
  $fp = fopen($tmp, 'wb');
  if (!$fp) throw new RuntimeException('sessions_save: cannot open tmp');
  if (!flock($fp, LOCK_EX)) { fclose($fp); throw new RuntimeException('sessions_save: cannot lock tmp'); }
  fwrite($fp, $json);
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);
  @rename($tmp, $p);
}

function client_ip_guess(): string {
  $keys = ['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'];
  foreach ($keys as $k) {
    $v = (string)($_SERVER[$k] ?? '');
    if ($v === '') continue;
    if (strpos($v, ',') !== false) $v = trim(explode(',', $v)[0]);
    return $v;
  }
  return '';
}

function session_current_id_safe(): string {
  $sid = session_id();
  return is_string($sid) ? $sid : '';
}

/**
 * Якщо поточна сесія була “revoked” для цього юзера — розлогінити.
 */
function session_enforce_not_revoked(string $uid): void {
  $uid = (string)$uid;
  if ($uid === '') return;
  $sid = session_current_id_safe();
  if ($sid === '') return;

  $data = sessions_load();
  $u = $data['users'][$uid] ?? null;
  if (!is_array($u)) return;

  $revoked = $u['revoked'] ?? null;
  if (!is_array($revoked)) return;

  if (isset($revoked[$sid])) {
    // kill session
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
      @session_destroy();
    }
    header('Location: /login?reason=session_revoked', true, 302);
    exit;
  }
}

/**
 * Реєструє/оновлює поточну сесію для юзера
 */
function session_register_current(string $uid, string $label = ''): void {
  $uid = (string)$uid;
  if ($uid === '') return;
  if (session_status() !== PHP_SESSION_ACTIVE) return;

  $sid = session_current_id_safe();
  if ($sid === '') return;

  $data = sessions_load();
  if (!isset($data['users'][$uid]) || !is_array($data['users'][$uid])) {
    $data['users'][$uid] = ['sessions' => [], 'revoked' => []];
  }
  if (!isset($data['users'][$uid]['sessions']) || !is_array($data['users'][$uid]['sessions'])) {
    $data['users'][$uid]['sessions'] = [];
  }
  if (!isset($data['users'][$uid]['revoked']) || !is_array($data['users'][$uid]['revoked'])) {
    $data['users'][$uid]['revoked'] = [];
  }

  $now = gmdate('c');
  $ip = client_ip_guess();
  $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

  $existing = $data['users'][$uid]['sessions'][$sid] ?? null;
  if (is_array($existing)) {
    $existing['last_seen'] = $now;
    $existing['ip'] = $ip !== '' ? $ip : (string)($existing['ip'] ?? '');
    $existing['ua'] = $ua !== '' ? $ua : (string)($existing['ua'] ?? '');
    if ($label !== '') $existing['label'] = $label;
    $data['users'][$uid]['sessions'][$sid] = $existing;
  } else {
    $data['users'][$uid]['sessions'][$sid] = [
      'sid' => $sid,
      'ip' => $ip,
      'ua' => $ua,
      'created_at' => $now,
      'last_seen' => $now,
      'label' => $label,
    ];
  }

  sessions_save($data);
}

/**
 * Повертає список активних сесій (масив записів)
 */
function sessions_list_for_user(string $uid): array {
  $uid = (string)$uid;
  if ($uid === '') return [];
  $data = sessions_load();
  $u = $data['users'][$uid] ?? null;
  if (!is_array($u)) return [];
  $sessions = $u['sessions'] ?? null;
  if (!is_array($sessions)) return [];

  $out = [];
  foreach ($sessions as $sid => $row) {
    if (!is_array($row)) continue;
    $row['sid'] = (string)($row['sid'] ?? $sid);
    $out[] = $row;
  }

  usort($out, function($a, $b){
    return strcmp((string)($b['last_seen'] ?? ''), (string)($a['last_seen'] ?? ''));
  });

  return $out;
}

/**
 * Відкликає конкретну сесію (додає в revoked + прибирає з active)
 */
function session_revoke_for_user(string $uid, string $sid): void {
  $uid = (string)$uid;
  $sid = (string)$sid;
  if ($uid === '' || $sid === '') return;

  $data = sessions_load();
  if (!isset($data['users'][$uid]) || !is_array($data['users'][$uid])) {
    $data['users'][$uid] = ['sessions' => [], 'revoked' => []];
  }
  if (!isset($data['users'][$uid]['sessions']) || !is_array($data['users'][$uid]['sessions'])) {
    $data['users'][$uid]['sessions'] = [];
  }
  if (!isset($data['users'][$uid]['revoked']) || !is_array($data['users'][$uid]['revoked'])) {
    $data['users'][$uid]['revoked'] = [];
  }

  unset($data['users'][$uid]['sessions'][$sid]);
  $data['users'][$uid]['revoked'][$sid] = gmdate('c');

  sessions_save($data);
}

/**
 * Відкликає всі сесії користувача (опційно крім поточної)
 */
function sessions_revoke_all_for_user(string $uid, ?string $exceptSid = null): void {
  $uid = (string)$uid;
  if ($uid === '') return;

  $data = sessions_load();
  if (!isset($data['users'][$uid]) || !is_array($data['users'][$uid])) {
    $data['users'][$uid] = ['sessions' => [], 'revoked' => []];
  }
  if (!isset($data['users'][$uid]['sessions']) || !is_array($data['users'][$uid]['sessions'])) {
    $data['users'][$uid]['sessions'] = [];
  }
  if (!isset($data['users'][$uid]['revoked']) || !is_array($data['users'][$uid]['revoked'])) {
    $data['users'][$uid]['revoked'] = [];
  }

  $sessions = $data['users'][$uid]['sessions'];
  foreach ($sessions as $sid => $row) {
    if ($exceptSid !== null && $sid === $exceptSid) continue;
    unset($data['users'][$uid]['sessions'][$sid]);
    $data['users'][$uid]['revoked'][(string)$sid] = gmdate('c');
  }

  sessions_save($data);
}