<?php
declare(strict_types=1);

/**
 * ProstoPDR users_store.php
 *
 * ✅ Тепер USERS беруться з Postgres (Railway), а не з /data/users.json
 * ✅ Сесії та revoked-сесії залишаємо як і було — у /storage/sessions.json
 */

require_once __DIR__ . '/db.php';

/* ============================================================
   USERS (POSTGRES)
============================================================ */

function users_pdo(): PDO {
  return function_exists('pdoi') ? pdoi() : db();
}

function users_now_iso(): string {
  return gmdate('c');
}

function users_generate_id(): string {
  return bin2hex(random_bytes(16));
}

function users_nullable_string(mixed $value): ?string {
  if ($value === null) return null;
  $s = trim((string)$value);
  if ($s === '' || strtolower($s) === 'null') return null;
  return $s;
}

function users_bool(mixed $value): bool {
  if (is_bool($value)) return $value;
  if (is_int($value)) return $value !== 0;
  $s = strtolower(trim((string)$value));
  return in_array($s, ['1', 'true', 't', 'yes', 'y', 'on'], true);
}

function users_dt_or_null(mixed $value): ?string {
  $s = users_nullable_string($value);
  if ($s === null) return null;

  $ts = strtotime($s);
  if ($ts === false) {
    return $s;
  }

  return gmdate('c', $ts);
}

function user_normalize(array $u): array {
  $u['id'] = trim((string)($u['id'] ?? ''));
  $u['email'] = strtolower(trim((string)($u['email'] ?? '')));
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

  $u['google_sub'] = users_nullable_string($u['google_sub'] ?? null);

  $u['trial_used'] = users_bool($u['trial_used'] ?? false);
  $u['trial_cancelled'] = users_bool($u['trial_cancelled'] ?? false);

  $u['trial_started_at'] = users_dt_or_null($u['trial_started_at'] ?? null);
  $u['trial_expires_at'] = users_dt_or_null($u['trial_expires_at'] ?? null);

  $u['paid_at'] = users_dt_or_null($u['paid_at'] ?? null);
  $u['expires_at'] = users_dt_or_null($u['expires_at'] ?? null);
  $u['plan_set_at'] = users_dt_or_null($u['plan_set_at'] ?? null);
  $u['mono_last_payment_at'] = users_dt_or_null($u['mono_last_payment_at'] ?? null);

  $u['buy_pending_invoice'] = users_nullable_string($u['buy_pending_invoice'] ?? null);
  $u['buy_pending_plan'] = users_nullable_string($u['buy_pending_plan'] ?? null);
  $u['trial_pending_plan'] = users_nullable_string($u['trial_pending_plan'] ?? null);

  $u['created_at'] = users_dt_or_null($u['created_at'] ?? null) ?? users_now_iso();

  return $u;
}

function users_all(): array {
  $rows = db_all_users(users_pdo());

  $out = [];
  foreach ($rows as $row) {
    if (!is_array($row)) continue;
    $out[] = user_normalize($row);
  }

  return $out;
}

function user_find_by_id(int|string $id): ?array {
  $sid = trim((string)$id);
  if ($sid === '') return null;

  $row = db_find_user_by_id(users_pdo(), $sid);
  return is_array($row) ? user_normalize($row) : null;
}

function user_find_by_email(string $email): ?array {
  $email = strtolower(trim($email));
  if ($email === '') return null;

  $row = db_find_user_by_email(users_pdo(), $email);
  return is_array($row) ? user_normalize($row) : null;
}

function user_find_by_google_sub(string $sub): ?array {
  $sub = trim($sub);
  if ($sub === '') return null;

  $row = db_find_user_by_google_sub(users_pdo(), $sub);
  return is_array($row) ? user_normalize($row) : null;
}

function user_verify_password(array $user, string $password): bool {
  $hash = (string)($user['password_hash'] ?? '');
  if ($hash === '' || $password === '') return false;
  return password_verify($password, $hash);
}

function user_update(int|string $id, array $patch): array {
  $sid = trim((string)$id);

  $existing = user_find_by_id($sid);
  if (!is_array($existing)) {
    $existing = [
      'id' => $sid,
      'email' => '',
      'name' => '',
      'password_hash' => '',
      'google_sub' => null,

      'plan' => 'free',
      'expires_at' => null,

      'trial_used' => false,
      'trial_started_at' => null,
      'trial_expires_at' => null,
      'trial_cancelled' => false,

      'paid_at' => null,
      'plan_set_at' => null,
      'mono_last_payment_at' => null,

      'buy_pending_invoice' => null,
      'buy_pending_plan' => null,
      'trial_pending_plan' => null,

      'created_at' => users_now_iso(),
    ];
  }

  $merged = user_normalize(array_merge($existing, $patch, ['id' => $sid]));
  db_upsert_user(users_pdo(), $merged);

  return user_find_by_id($sid) ?? $merged;
}

function user_save(array $user): array {
  $id = trim((string)($user['id'] ?? ''));
  if ($id === '') {
    throw new InvalidArgumentException('user_save: empty id');
  }
  return user_update($id, $user);
}

function user_upsert(array $user): array {
  $user = user_normalize($user);

  if ($user['id'] === '') {
    $existing = user_find_by_email((string)($user['email'] ?? ''));
    if ($existing) {
      $user['id'] = (string)$existing['id'];
      if ($user['created_at'] === '') {
        $user['created_at'] = (string)($existing['created_at'] ?? users_now_iso());
      }
    } else {
      $user['id'] = users_generate_id();
    }
  }

  db_upsert_user(users_pdo(), $user);

  return user_find_by_id($user['id']) ?? $user;
}

/**
 * Compatible modes:
 * 1) user_create(array $user): array
 * 2) user_create($email, $name, $passwordHash): string id
 */
function user_create(mixed $arg1, ?string $name = null, ?string $passwordHash = null): mixed {
  if (is_array($arg1)) {
    $user = user_normalize($arg1);

    if ($user['id'] === '') {
      $user['id'] = users_generate_id();
    }
    if ($user['created_at'] === '') {
      $user['created_at'] = users_now_iso();
    }

    if ($user['email'] !== '') {
      $existing = user_find_by_email($user['email']);
      if ($existing) {
        throw new RuntimeException('user_create: email already exists');
      }
    }

    return user_upsert($user);
  }

  $email = strtolower(trim((string)$arg1));
  $name = trim((string)$name);
  $passwordHash = (string)$passwordHash;

  if ($email === '') {
    throw new InvalidArgumentException('user_create: empty email');
  }

  if (user_find_by_email($email) !== null) {
    throw new RuntimeException('user_create: email already exists');
  }

  $saved = user_upsert([
    'id' => users_generate_id(),
    'email' => $email,
    'name' => $name,
    'password_hash' => $passwordHash,
    'plan' => 'free',
    'paid_at' => null,
    'expires_at' => null,
    'created_at' => users_now_iso(),
  ]);

  return (string)$saved['id'];
}

function user_delete(int|string $id): bool {
  $sid = trim((string)$id);
  if ($sid === '') return false;

  $deleted = db_delete_user(users_pdo(), $sid);

  if ($deleted && function_exists('sessions_delete_user_all')) {
    sessions_delete_user_all($sid);
  }

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
  // no-op for Postgres version
}

/* ============================================================
   OAUTH
============================================================ */

if (!function_exists('oauth_normalize')) {
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
}

if (!function_exists('oauth_find')) {
  function oauth_find(string $provider, string $sub): ?array {
    $provider = strtolower(trim($provider));
    $sub = trim($sub);
    if ($provider === '' || $sub === '') return null;

    if ($provider === 'google') {
      $user = user_find_by_google_sub($sub);
      if (!$user) return null;

      return [
        'provider' => 'google',
        'sub' => $sub,
        'user_id' => (string)$user['id'],
        'email' => (string)($user['email'] ?? ''),
        'name' => (string)($user['name'] ?? ''),
        'linked_at' => (string)($user['created_at'] ?? gmdate('c')),
      ];
    }

    return null;
  }
}

if (!function_exists('oauth_user_id_by_provider_sub')) {
  function oauth_user_id_by_provider_sub(string $provider, string $sub): ?string {
    $r = oauth_find($provider, $sub);
    if (!$r) return null;
    $uid = trim((string)($r['user_id'] ?? ''));
    return $uid !== '' ? $uid : null;
  }
}

if (!function_exists('oauth_find_by_email')) {
  function oauth_find_by_email(string $provider, string $email): ?array {
    $provider = strtolower(trim($provider));
    $email = strtolower(trim($email));
    if ($provider === '' || $email === '') return null;

    if ($provider === 'google') {
      $user = user_find_by_email($email);
      if (!$user) return null;

      $sub = trim((string)($user['google_sub'] ?? ''));
      if ($sub === '') return null;

      return [
        'provider' => 'google',
        'sub' => $sub,
        'user_id' => (string)$user['id'],
        'email' => (string)($user['email'] ?? ''),
        'name' => (string)($user['name'] ?? ''),
        'linked_at' => (string)($user['created_at'] ?? gmdate('c')),
      ];
    }

    return null;
  }
}

if (!function_exists('oauth_link')) {
  function oauth_link(string $provider, string $sub, string $userId, string $email = '', string $name = ''): array {
    $provider = strtolower(trim($provider));
    $sub = trim($sub);
    $userId = trim($userId);
    $email = trim($email);
    $name = trim($name);

    if ($provider === '' || $sub === '' || $userId === '') {
      throw new InvalidArgumentException('oauth_link: provider/sub/userId required');
    }

    if ($provider !== 'google') {
      throw new InvalidArgumentException('oauth_link: unsupported provider');
    }

    $user = user_find_by_id($userId);
    if (!$user) {
      throw new RuntimeException('oauth_link: user not found');
    }

    $patch = ['google_sub' => $sub];
    if ($email !== '') $patch['email'] = strtolower($email);
    if ($name !== '') $patch['name'] = $name;

    $saved = user_update($userId, $patch);

    return [
      'provider' => 'google',
      'sub' => $sub,
      'user_id' => (string)$saved['id'],
      'email' => (string)($saved['email'] ?? ''),
      'name' => (string)($saved['name'] ?? ''),
      'linked_at' => (string)($saved['created_at'] ?? gmdate('c')),
    ];
  }
}

/* ============================================================
   SESSIONS / DEVICES
   (залишено сумісним із твоєю старою логікою)
============================================================ */

if (!function_exists('sessions_store_path')) {
  function sessions_store_path(): string {
    return dirname(__DIR__) . '/storage/sessions.json';
  }
}

if (!function_exists('sessions_store_ensure_exists')) {
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
}

if (!function_exists('sessions_load')) {
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
}

if (!function_exists('sessions_save')) {
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
}

if (!function_exists('sessions_user_bucket_ensure')) {
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
}

if (!function_exists('client_ip_guess')) {
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
}

if (!function_exists('session_current_id_safe')) {
  function session_current_id_safe(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) return '';
    $sid = session_id();
    return is_string($sid) ? $sid : '';
  }
}

if (!function_exists('session_enforce_not_revoked')) {
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
}

if (!function_exists('session_register_current')) {
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
}

if (!function_exists('session_unregister_current_for_user')) {
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
}

if (!function_exists('sessions_list_for_user')) {
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
}

if (!function_exists('session_revoke_for_user')) {
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
}

if (!function_exists('sessions_revoke_all_for_user')) {
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
}

if (!function_exists('sessions_delete_user_all')) {
  function sessions_delete_user_all(string $uid): void {
    $uid = trim($uid);
    if ($uid === '') return;

    $data = sessions_load();
    if (isset($data['users'][$uid])) {
      unset($data['users'][$uid]);
      sessions_save($data);
    }
  }
}

if (!function_exists('sessions_repair_and_save')) {
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
      foreach ($data['users'] as $uid => $bucket) {
        if (!is_array($bucket)) {
          $data['users'][$uid] = ['sessions' => [], 'revoked' => []];
          continue;
        }
        if (!isset($bucket['sessions']) || !is_array($bucket['sessions'])) {
          $bucket['sessions'] = [];
        }
        if (!isset($bucket['revoked']) || !is_array($bucket['revoked'])) {
          $bucket['revoked'] = [];
        }
        $data['users'][$uid] = $bucket;
      }

      sessions_save($data);
      return;
    }

    $new = ['users' => []];

    foreach ($data as $uid => $bucket) {
      if (!is_array($bucket)) continue;

      $new['users'][(string)$uid] = [
        'sessions' => [],
        'revoked' => [],
      ];

      foreach ($bucket as $sid => $row) {
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

        $new['users'][(string)$uid]['sessions'][$row['sid']] = $row;
      }
    }

    sessions_save($new);
  }
}