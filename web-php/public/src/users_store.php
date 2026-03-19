<?php
declare(strict_types=1);

/**
 * ProstoPDR users_store.php
 *
 * users storage:
 * /data/users.json
 *
 * sessions storage:
 * /storage/sessions.json
 *
 * Формат users.json:
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
 *   ],
 *   "oauth": [
 *     {
 *       "provider":"google|apple",
 *       "sub":"provider_user_id",
 *       "user_id":"1",
 *       "email":"optional",
 *       "name":"optional",
 *       "linked_at":"2026-02-21T10:00:00+00:00"
 *     }
 *   ]
 * }
 *
 * Формат sessions.json:
 * {
 *   "users": {
 *     "1": {
 *       "sessions": {
 *         "PHPSESSID...": {
 *           "sid":"...",
 *           "ip":"...",
 *           "ua":"...",
 *           "created_at":"...",
 *           "last_seen":"...",
 *           "label":"..."
 *         }
 *       },
 *       "revoked": {
 *         "OLDSID": "2026-02-25T10:00:00+00:00"
 *       }
 *     }
 *   }
 * }
 */

/* ============================================================
   USERS JSON
============================================================ */

function users_store_path(): string {
  return '/data/users.json';
}

function users_store_ensure_exists(): void {
  $path = users_store_path();
  $dir = dirname($path);

  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
  }

  if (!file_exists($path)) {
    file_put_contents(
      $path,
      json_encode(
        ['users' => [], 'oauth' => []],
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
      )
    );
  }
}

users_store_ensure_exists();

/**
 * Завжди повертає ['users' => [...], 'oauth' => [...]]
 * Підхоплює старі/криві формати, але нормалізує на save.
 */
function users_load(): array {
  $path = users_store_path();
  if (!is_file($path)) return ['users' => [], 'oauth' => []];

  $raw = (string)file_get_contents($path);
  if (trim($raw) === '') return ['users' => [], 'oauth' => []];

  $data = json_decode($raw, true);
  if (!is_array($data)) return ['users' => [], 'oauth' => []];

  if (isset($data['users']) && is_array($data['users'])) {
    $users = array_values(array_filter($data['users'], 'is_array'));
    $oauth = [];

    if (isset($data['oauth']) && is_array($data['oauth'])) {
      $oauth = array_values(array_filter($data['oauth'], 'is_array'));
    }

    return ['users' => $users, 'oauth' => $oauth];
  }

  if (array_is_list($data) && (count($data) === 0 || is_array($data[0] ?? null))) {
    return ['users' => array_values(array_filter($data, 'is_array')), 'oauth' => []];
  }

  $isMap = true;
  foreach ($data as $k => $v) {
    if (!is_string($k) && !is_int($k)) {
      $isMap = false;
      break;
    }
    if (!is_array($v)) {
      $isMap = false;
      break;
    }
  }

  if ($isMap) {
    $users = [];
    foreach ($data as $u) {
      if (is_array($u)) $users[] = $u;
    }
    return ['users' => array_values($users), 'oauth' => []];
  }

  return ['users' => [], 'oauth' => []];
}

/**
 * Атомарний запис users + oauth
 */
function users_save(array $store): void {
  $path = users_store_path();

  if (!isset($store['users']) || !is_array($store['users'])) {
    $store['users'] = [];
  }
  if (!isset($store['oauth']) || !is_array($store['oauth'])) {
    $store['oauth'] = [];
  }

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
  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
  }

  $json = json_encode(
    ['users' => array_values($outUsers), 'oauth' => array_values($outOauth)],
    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
  );
  if ($json === false) {
    throw new RuntimeException('users_save: json_encode failed');
  }

  $tmp = $path . '.tmp';
  $fp = fopen($tmp, 'wb');
  if (!$fp) {
    throw new RuntimeException('users_save: cannot open tmp');
  }

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

function user_normalize(array $u): array {
  $u['id'] = trim((string)($u['id'] ?? ''));
  $u['email'] = trim((string)($u['email'] ?? ''));
  $u['name'] = trim((string)($u['name'] ?? ''));
  $u['password_hash'] = (string)($u['password_hash'] ?? '');

  $plan = strtolower(trim((string)($u['plan'] ?? 'free')));
  if ($plan === '' || $plan === 'null' || $plan === 'none') {
    $plan = 'free';
  }
  if (!in_array($plan, ['free', 'basic', 'personal', 'dev'], true)) {
    $plan = 'free';
  }
  $u['plan'] = $plan;

  $paidAt = $u['paid_at'] ?? null;
  $u['paid_at'] = ($paidAt === '' || $paidAt === 'null') ? null : $paidAt;

  $expiresAt = $u['expires_at'] ?? null;
  $u['expires_at'] = ($expiresAt === '' || $expiresAt === 'null') ? null : $expiresAt;

  $u['created_at'] = (string)($u['created_at'] ?? gmdate('c'));

  return $u;
}

function user_find_by_id(int|string $id): ?array {
  $sid = (string)$id;
  foreach (users_all() as $u) {
    if (!is_array($u)) continue;
    if ((string)($u['id'] ?? '') === $sid) {
      return user_normalize($u);
    }
  }
  return null;
}

function user_find_by_email(string $email): ?array {
  $email = strtolower(trim($email));
  if ($email === '') return null;

  foreach (users_all() as $u) {
    if (!is_array($u)) continue;
    if (strtolower(trim((string)($u['email'] ?? ''))) === $email) {
      return user_normalize($u);
    }
  }
  return null;
}

function user_next_id(): string {
  $max = 0;
  foreach (users_all() as $u) {
    if (!is_array($u)) continue;
    $id = (string)($u['id'] ?? '');
    if (ctype_digit($id)) {
      $n = (int)$id;
      if ($n > $max) $max = $n;
    }
  }
  return (string)($max + 1);
}

/**
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

function user_save(array $user): array {
  $id = (string)($user['id'] ?? '');
  if ($id === '') {
    throw new InvalidArgumentException('user_save: empty id');
  }
  return user_update($id, $user);
}

function user_upsert(array $user): array {
  $id = trim((string)($user['id'] ?? ''));
  if ($id === '') {
    $id = user_next_id();
    $user['id'] = $id;
  }
  return user_save($user);
}

function user_create(array $user): array {
  $user['id'] = trim((string)($user['id'] ?? ''));
  if ($user['id'] === '') {
    $user['id'] = user_next_id();
  }

  if (user_find_by_id($user['id']) !== null) {
    throw new RuntimeException('user_create: user id already exists');
  }

  return user_upsert($user);
}

function user_delete(int|string $id): bool {
  $sid = (string)$id;
  if ($sid === '') return false;

  $store = users_load();
  $users = $store['users'] ?? [];
  $oauth = $store['oauth'] ?? [];

  $newUsers = [];
  $deleted = false;

  foreach ($users as $u) {
    if (!is_array($u)) continue;
    if ((string)($u['id'] ?? '') === $sid) {
      $deleted = true;
      continue;
    }
    $newUsers[] = $u;
  }

  $newOauth = [];
  foreach ($oauth as $row) {
    if (!is_array($row)) continue;
    if ((string)($row['user_id'] ?? '') === $sid) continue;
    $newOauth[] = $row;
  }

  $store['users'] = array_values($newUsers);
  $store['oauth'] = array_values($newOauth);
  users_save($store);

  sessions_delete_user_all($sid);

  return $deleted;
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

function users_repair_and_save(): void {
  $store = users_load();
  users_save($store);
}

/* ============================================================
   OAUTH
============================================================ */

function oauth_normalize(array $row): array {
  $provider = strtolower(trim((string)($row['provider'] ?? '')));
  $sub = trim((string)($row['sub'] ?? ''));
  $userId = trim((string)($row['user_id'] ?? ''));

  $email = trim((string)($row['email'] ?? ''));
  $name = trim((string)($row['name'] ?? ''));

  $linkedAt = (string)($row['linked_at'] ?? '');
  if ($linkedAt === '') $linkedAt = gmdate('c');

  return [
    'provider' => $provider,
    'sub' => $sub,
    'user_id' => $userId,
    'email' => $email,
    'name' => $name,
    'linked_at' => $linkedAt,
  ];
}

function oauth_find(string $provider, string $sub): ?array {
  $provider = strtolower(trim($provider));
  $sub = trim($sub);
  if ($provider === '' || $sub === '') return null;

  $store = users_load();
  $list = $store['oauth'] ?? [];
  if (!is_array($list)) return null;

  foreach ($list as $row) {
    if (!is_array($row)) continue;
    $r = oauth_normalize($row);
    if ($r['provider'] === $provider && $r['sub'] === $sub) {
      return $r;
    }
  }

  return null;
}

function oauth_user_id_by_provider_sub(string $provider, string $sub): ?string {
  $r = oauth_find($provider, $sub);
  if (!$r) return null;

  $uid = (string)($r['user_id'] ?? '');
  return $uid !== '' ? $uid : null;
}

function oauth_find_by_email(string $provider, string $email): ?array {
  $provider = strtolower(trim($provider));
  $email = strtolower(trim($email));
  if ($provider === '' || $email === '') return null;

  $store = users_load();
  $list = $store['oauth'] ?? [];
  if (!is_array($list)) return null;

  foreach ($list as $row) {
    if (!is_array($row)) continue;
    $r = oauth_normalize($row);
    if ($r['provider'] !== $provider) continue;
    if (strtolower((string)($r['email'] ?? '')) === $email) {
      return $r;
    }
  }

  return null;
}

function oauth_link(string $provider, string $sub, string $userId, string $email = '', string $name = ''): array {
  $provider = strtolower(trim($provider));
  $sub = trim($sub);
  $userId = trim($userId);
  $email = trim($email);
  $name = trim($name);

  if ($provider === '' || $sub === '' || $userId === '') {
    throw new InvalidArgumentException('oauth_link: provider/sub/userId required');
  }

  $store = users_load();
  $list = $store['oauth'] ?? [];
  if (!is_array($list)) $list = [];

  $found = false;
  for ($i = 0; $i < count($list); $i++) {
    if (!is_array($list[$i])) continue;

    $r = oauth_normalize($list[$i]);
    if ($r['provider'] === $provider && $r['sub'] === $sub) {
      $found = true;
      $r['user_id'] = $userId;
      if ($email !== '') $r['email'] = $email;
      if ($name !== '') $r['name'] = $name;
      $list[$i] = $r;
      break;
    }
  }

  if (!$found) {
    $list[] = oauth_normalize([
      'provider' => $provider,
      'sub' => $sub,
      'user_id' => $userId,
      'email' => $email,
      'name' => $name,
      'linked_at' => gmdate('c'),
    ]);
  }

  $store['oauth'] = array_values($list);
  users_save($store);

  return oauth_find($provider, $sub) ?? oauth_normalize([
    'provider' => $provider,
    'sub' => $sub,
    'user_id' => $userId,
    'email' => $email,
    'name' => $name,
    'linked_at' => gmdate('c'),
  ]);
}

/* ============================================================
   SESSIONS / DEVICES
============================================================ */

function sessions_store_path(): string {
  return dirname(__DIR__) . '/storage/sessions.json';
}

function sessions_store_ensure_exists(): void {
  $path = sessions_store_path();
  $dir = dirname($path);

  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
  }

  if (!file_exists($path)) {
    file_put_contents(
      $path,
      json_encode(['users' => []], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );
  }
}

sessions_store_ensure_exists();

function sessions_load(): array {
  $p = sessions_store_path();
  if (!is_file($p)) return ['users' => []];

  $raw = (string)file_get_contents($p);
  if (trim($raw) === '') return ['users' => []];

  $data = json_decode($raw, true);
  if (!is_array($data)) return ['users' => []];

  if (!isset($data['users']) || !is_array($data['users'])) {
    $data['users'] = [];
  }

  return $data;
}

function sessions_save(array $data): void {
  if (!isset($data['users']) || !is_array($data['users'])) {
    $data['users'] = [];
  }

  $p = sessions_store_path();
  $dir = dirname($p);
  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
  }

  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  if ($json === false) {
    throw new RuntimeException('sessions_save: json_encode failed');
  }

  $tmp = $p . '.tmp';
  $fp = fopen($tmp, 'wb');
  if (!$fp) {
    throw new RuntimeException('sessions_save: cannot open tmp');
  }

  if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    throw new RuntimeException('sessions_save: cannot lock tmp');
  }

  fwrite($fp, $json);
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);

  @rename($tmp, $p);
}

function sessions_user_bucket_ensure(array &$data, string $uid): void {
  if (!isset($data['users'][$uid]) || !is_array($data['users'][$uid])) {
    $data['users'][$uid] = ['sessions' => [], 'revoked' => []];
  }
  if (!isset($data['users'][$uid]['sessions']) || !is_array($data['users'][$uid]['sessions'])) {
    $data['users'][$uid]['sessions'] = [];
  }
  if (!isset($data['users'][$uid]['revoked']) || !is_array($data['users'][$uid]['revoked'])) {
    $data['users'][$uid]['revoked'] = [];
  }
}

function client_ip_guess(): string {
  $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];

  foreach ($keys as $k) {
    $v = (string)($_SERVER[$k] ?? '');
    if ($v === '') continue;
    if (strpos($v, ',') !== false) {
      $v = trim(explode(',', $v)[0]);
    }
    return $v;
  }

  return '';
}

function session_current_id_safe(): string {
  if (session_status() !== PHP_SESSION_ACTIVE) return '';
  $sid = session_id();
  return is_string($sid) ? $sid : '';
}

/**
 * Якщо поточна сесія revoked — викидає на логін.
 */
function session_enforce_not_revoked(string $uid): void {
  $uid = trim($uid);
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

    if (session_status() === PHP_SESSION_ACTIVE) {
      @session_destroy();
    }

    header('Location: /login?reason=session_revoked', true, 302);
    exit;
  }
}

/**
 * Реєструє/оновлює поточну сесію для юзера.
 * Треба викликати після логіну і на авторизованих сторінках.
 */
function session_register_current(string $uid, string $label = ''): void {
  $uid = trim($uid);
  if ($uid === '') return;
  if (session_status() !== PHP_SESSION_ACTIVE) return;

  $sid = session_current_id_safe();
  if ($sid === '') return;

  $data = sessions_load();
  sessions_user_bucket_ensure($data, $uid);

  $now = gmdate('c');
  $ip = client_ip_guess();
  $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

  $existing = $data['users'][$uid]['sessions'][$sid] ?? null;
  if (is_array($existing)) {
    $existing['sid'] = $sid;
    $existing['last_seen'] = $now;
    $existing['ip'] = $ip !== '' ? $ip : (string)($existing['ip'] ?? '');
    $existing['ua'] = $ua !== '' ? $ua : (string)($existing['ua'] ?? '');
    if ($label !== '') {
      $existing['label'] = $label;
    } elseif (!isset($existing['label'])) {
      $existing['label'] = '';
    }

    if (!isset($existing['created_at']) || trim((string)$existing['created_at']) === '') {
      $existing['created_at'] = $now;
    }

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

  if (isset($data['users'][$uid]['revoked'][$sid])) {
    unset($data['users'][$uid]['revoked'][$sid]);
  }

  sessions_save($data);
}

/**
 * Прибрати поточну сесію з активних, наприклад при logout.
 */
function session_unregister_current_for_user(string $uid): void {
  $uid = trim($uid);
  if ($uid === '') return;

  $sid = session_current_id_safe();
  if ($sid === '') return;

  $data = sessions_load();
  sessions_user_bucket_ensure($data, $uid);

  if (isset($data['users'][$uid]['sessions'][$sid])) {
    unset($data['users'][$uid]['sessions'][$sid]);
    sessions_save($data);
  }
}

/**
 * Список активних сесій користувача.
 */
function sessions_list_for_user(string $uid): array {
  $uid = trim($uid);
  if ($uid === '') return [];

  $data = sessions_load();
  $u = $data['users'][$uid] ?? null;
  if (!is_array($u)) return [];

  $sessions = $u['sessions'] ?? null;
  if (!is_array($sessions)) return [];

  $out = [];
  foreach ($sessions as $sid => $row) {
    if (!is_array($row)) continue;

    $row['sid'] = trim((string)($row['sid'] ?? $sid));
    if ($row['sid'] === '') {
      $row['sid'] = (string)$sid;
    }

    $row['ip'] = (string)($row['ip'] ?? '');
    $row['ua'] = (string)($row['ua'] ?? '');
    $row['created_at'] = (string)($row['created_at'] ?? '');
    $row['last_seen'] = (string)($row['last_seen'] ?? '');
    $row['label'] = (string)($row['label'] ?? '');

    $out[] = $row;
  }

  usort($out, function(array $a, array $b): int {
    return strcmp((string)($b['last_seen'] ?? ''), (string)($a['last_seen'] ?? ''));
  });

  return $out;
}

/**
 * Відкликає конкретну сесію.
 */
function session_revoke_for_user(string $uid, string $sid): void {
  $uid = trim($uid);
  $sid = trim($sid);
  if ($uid === '' || $sid === '') return;

  $data = sessions_load();
  sessions_user_bucket_ensure($data, $uid);

  if (isset($data['users'][$uid]['sessions'][$sid])) {
    unset($data['users'][$uid]['sessions'][$sid]);
  }

  $data['users'][$uid]['revoked'][$sid] = gmdate('c');

  sessions_save($data);
}

/**
 * Відкликає всі сесії користувача, опційно крім однієї.
 */
function sessions_revoke_all_for_user(string $uid, ?string $exceptSid = null): void {
  $uid = trim($uid);
  if ($uid === '') return;

  $data = sessions_load();
  sessions_user_bucket_ensure($data, $uid);

  $exceptSid = $exceptSid !== null ? trim($exceptSid) : null;

  foreach ($data['users'][$uid]['sessions'] as $sid => $row) {
    $sid = (string)$sid;
    if ($exceptSid !== null && $sid === $exceptSid) {
      continue;
    }

    unset($data['users'][$uid]['sessions'][$sid]);
    $data['users'][$uid]['revoked'][$sid] = gmdate('c');
  }

  sessions_save($data);
}

/**
 * Повне очищення bucket користувача із sessions.json
 */
function sessions_delete_user_all(string $uid): void {
  $uid = trim($uid);
  if ($uid === '') return;

  $data = sessions_load();
  if (isset($data['users'][$uid])) {
    unset($data['users'][$uid]);
    sessions_save($data);
  }
}

/**
 * Ремонт sessions.json до нового формату.
 * Мігрує старий формат:
 * {
 *   "uid": {
 *     "sid1": {...},
 *     "sid2": {...}
 *   }
 * }
 * у новий:
 * {
 *   "users": {
 *     "uid": {
 *       "sessions": {...},
 *       "revoked": {}
 *     }
 *   }
 * }
 */
function sessions_repair_and_save(): void {
  $path = sessions_store_path();
  if (!is_file($path)) {
    sessions_save(['users' => []]);
    return;
  }

  $raw = (string)file_get_contents($path);
  if (trim($raw) === '') {
    sessions_save(['users' => []]);
    return;
  }

  $data = json_decode($raw, true);
  if (!is_array($data)) {
    sessions_save(['users' => []]);
    return;
  }

  if (isset($data['users']) && is_array($data['users'])) {
    $fixed = ['users' => []];

    foreach ($data['users'] as $uid => $bucket) {
      $uid = (string)$uid;
      if ($uid === '' || !is_array($bucket)) continue;

      $sessions = $bucket['sessions'] ?? [];
      $revoked = $bucket['revoked'] ?? [];

      if (!is_array($sessions)) $sessions = [];
      if (!is_array($revoked)) $revoked = [];

      $fixed['users'][$uid] = [
        'sessions' => [],
        'revoked' => [],
      ];

      foreach ($sessions as $sid => $row) {
        if (!is_array($row)) continue;
        $sid = trim((string)($row['sid'] ?? $sid));
        if ($sid === '') continue;

        $fixed['users'][$uid]['sessions'][$sid] = [
          'sid' => $sid,
          'ip' => (string)($row['ip'] ?? ''),
          'ua' => (string)($row['ua'] ?? ''),
          'created_at' => (string)($row['created_at'] ?? ''),
          'last_seen' => (string)($row['last_seen'] ?? ''),
          'label' => (string)($row['label'] ?? ''),
        ];
      }

      foreach ($revoked as $sid => $ts) {
        $sid = trim((string)$sid);
        if ($sid === '') continue;
        $fixed['users'][$uid]['revoked'][$sid] = (string)$ts;
      }
    }

    sessions_save($fixed);
    return;
  }

  $fixed = ['users' => []];

  foreach ($data as $uid => $legacySessions) {
    $uid = (string)$uid;
    if ($uid === '' || !is_array($legacySessions)) continue;

    $fixed['users'][$uid] = [
      'sessions' => [],
      'revoked' => [],
    ];

    foreach ($legacySessions as $sid => $row) {
      if (!is_array($row)) continue;
      $sid = trim((string)($row['sid'] ?? $sid));
      if ($sid === '') continue;

      $fixed['users'][$uid]['sessions'][$sid] = [
        'sid' => $sid,
        'ip' => (string)($row['ip'] ?? ''),
        'ua' => (string)($row['ua'] ?? ''),
        'created_at' => (string)($row['created_at'] ?? ''),
        'last_seen' => (string)($row['last_seen'] ?? ''),
        'label' => (string)($row['label'] ?? ''),
      ];
    }
  }

  sessions_save($fixed);
}