<?php
declare(strict_types=1);

/**
 * users_store.php
 *
 * storage/users.json:
 * {
 *   "users": [ {user}, ... ],
 *   "oauth": [ {provider, sub, user_id, ...}, ... ]   // якщо використовується
 * }
 *
 * id — STRING (uuid/hex).
 */

function users_store_path(): string {
  return dirname(__DIR__) . '/storage/users.json';
}

function users_load(): array {
  $path = users_store_path();
  if (!is_file($path)) return ['users' => [], 'oauth' => []];

  $raw = (string)file_get_contents($path);
  if (trim($raw) === '') return ['users' => [], 'oauth' => []];

  $data = json_decode($raw, true);
  if (!is_array($data)) return ['users' => [], 'oauth' => []];

  // нормальний формат
  if (isset($data['users']) && is_array($data['users'])) {
    if (!isset($data['oauth']) || !is_array($data['oauth'])) $data['oauth'] = [];
    return [
      'users' => array_values(array_filter($data['users'], 'is_array')),
      'oauth' => array_values(array_filter($data['oauth'], 'is_array')),
    ];
  }

  // fallback: якщо раптом був list users
  if (array_is_list($data) && (count($data) === 0 || is_array($data[0]))) {
    return ['users' => array_values(array_filter($data, 'is_array')), 'oauth' => []];
  }

  // fallback: map id => user
  $isMap = true;
  foreach ($data as $k => $v) {
    if (!is_string($k) && !is_int($k)) { $isMap = false; break; }
    if (!is_array($v)) { $isMap = false; break; }
  }
  if ($isMap) {
    $users = [];
    foreach ($data as $u) $users[] = $u;
    return ['users' => array_values(array_filter($users, 'is_array')), 'oauth' => []];
  }

  return ['users' => [], 'oauth' => []];
}

/**
 * Атомарний запис
 */
function users_save(array $store): void {
  $path = users_store_path();

  if (!isset($store['users']) || !is_array($store['users'])) $store['users'] = [];
  if (!isset($store['oauth']) || !is_array($store['oauth'])) $store['oauth'] = [];

  $outUsers = [];
  foreach ($store['users'] as $u) {
    if (!is_array($u)) continue;
    $nu = user_normalize($u);
    if ($nu['id'] === '') continue;
    $outUsers[] = $nu;
  }

  $outOauth = [];
  $seen = [];
  foreach ($store['oauth'] as $row) {
    if (!is_array($row)) continue;
    $nr = oauth_normalize($row);
    if ($nr['provider'] === '' || $nr['sub'] === '' || $nr['user_id'] === '') continue;
    $key = $nr['provider'] . '|' . $nr['sub'];
    if (isset($seen[$key])) continue;
    $seen[$key] = true;
    $outOauth[] = $nr;
  }

  $dir = dirname($path);
  if (!is_dir($dir)) @mkdir($dir, 0775, true);

  $json = json_encode(
    ['users' => array_values($outUsers), 'oauth' => array_values($outOauth)],
    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
  );
  if ($json === false) throw new RuntimeException('users_save: json_encode failed');

  $tmp = $path . '.tmp';
  $fp = fopen($tmp, 'wb');
  if (!$fp) throw new RuntimeException('users_save: cannot open tmp');
  if (!flock($fp, LOCK_EX)) { fclose($fp); throw new RuntimeException('users_save: cannot lock tmp'); }

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

function user_find_by_id(int|string $id): ?array {
  $sid = (string)$id;
  if ($sid === '') return null;

  foreach (users_all() as $u) {
    if (!is_array($u)) continue;
    if ((string)($u['id'] ?? '') === $sid) return user_normalize($u);
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

function user_save(array $user): array {
  $id = (string)($user['id'] ?? '');
  if ($id === '') throw new InvalidArgumentException('user_save: empty id');
  return user_update($id, $user);
}

function user_update(string $id, array $patch): array {
  $id = (string)$id;
  if ($id === '') throw new InvalidArgumentException('user_update: empty id');

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

/**
 * ✅ Перевірка доступу по плану/експайру
 * Під твої плани: free / base / 12d / personal / basic / dev
 */
function user_has_access(array $user, ?int $nowTs = null): bool {
  $plan = strtolower((string)($user['plan'] ?? 'free'));
  if ($plan === 'dev') return true;
  if ($plan === 'free' || $plan === '' || $plan === 'none' || $plan === 'null') return false;

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

/**
 * ✅ Оновити план + expires
 * days = 0 -> безстроково
 */
function user_set_plan(string $userId, string $plan, int $days): bool {
  $userId = (string)$userId;
  if ($userId === '') return false;

  $store = users_load();
  if (!isset($store['users']) || !is_array($store['users'])) $store['users'] = [];

  $now = time();
  $expires = ($days > 0) ? gmdate('c', $now + $days * 86400) : null;

  $changed = false;
  foreach ($store['users'] as &$u) {
    if (!is_array($u)) continue;
    if ((string)($u['id'] ?? '') !== $userId) continue;

    $u['plan'] = $plan;
    $u['paid_at'] = gmdate('c');
    $u['expires_at'] = $expires;
    $u['plan_set_at'] = gmdate('c');
    $changed = true;
    break;
  }
  unset($u);

  if (!$changed) return false;

  users_save($store);
  return true;
}

/* ============================================================
   OAUTH (якщо у тебе використовується)
============================================================ */

function oauth_normalize(array $r): array {
  return [
    'provider' => (string)($r['provider'] ?? ''),
    'sub' => (string)($r['sub'] ?? ''),
    'user_id' => (string)($r['user_id'] ?? ''),
    'email' => (string)($r['email'] ?? ''),
    'name' => (string)($r['name'] ?? ''),
    'linked_at' => (string)($r['linked_at'] ?? gmdate('c')),
  ];
}

/* ============================================================
   ✅ СЕСІЇ ПРИСТРОЇВ (одна система, без дублів)
   storage/sessions.json:
   {
     "users": {
       "UID": { "sessions": { "SID": {...}}, "revoked": { "SID": "time" } }
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
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) @session_destroy();
    header('Location: /login?reason=session_revoked', true, 302);
    exit;
  }
}

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

  // якщо sid був revoked — “розревокати”
  if (isset($data['users'][$uid]['revoked'][$sid])) unset($data['users'][$uid]['revoked'][$sid]);

  $data['users'][$uid]['sessions'][$sid] = [
    'sid' => $sid,
    'label' => (string)$label,
    'ip' => client_ip_guess(),
    'ua' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
    'created_at' => (string)($data['users'][$uid]['sessions'][$sid]['created_at'] ?? gmdate('c')),
    'last_seen' => gmdate('c'),
  ];

  sessions_save($data);
}

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
    if ($exceptSid !== null && (string)$sid === (string)$exceptSid) continue;
    unset($data['users'][$uid]['sessions'][$sid]);
    $data['users'][$uid]['revoked'][(string)$sid] = gmdate('c');
  }

  sessions_save($data);
}