<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * users_store.php (Postgres)
 *
 * API сумісний з кодом:
 * - users_all()
 * - user_find_by_id()
 * - user_find_by_email()
 * - user_create()
 * - user_verify_password()
 * - user_upsert()
 * - user_delete()
 * - user_has_access()
 *
 * + OAuth helpers:
 * - oauth_find()
 * - oauth_find_by_email()
 * - oauth_user_id_by_provider_sub()
 * - oauth_link()
 *
 * + Sessions / devices helpers:
 * - session_current_id_safe()
 * - session_register_current()
 * - session_enforce_not_revoked()
 * - session_unregister_current_for_user()
 * - sessions_list_for_user()
 * - session_revoke_for_user()
 * - sessions_revoke_all_for_user()
 * - sessions_delete_user_all()
 */

function dbi(): PDO {
  $pdo = db();
  ensure_schema($pdo);
  return $pdo;
}

function ensure_schema(PDO $pdo): void {
  // users table
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
      id            TEXT PRIMARY KEY,
      email         VARCHAR(190) UNIQUE,
      name          VARCHAR(190),
      password_hash VARCHAR(255),
      plan          VARCHAR(32) NOT NULL DEFAULT 'free',
      expires_at    TIMESTAMPTZ NULL,
      paid_at       TIMESTAMPTZ NULL,
      created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
      meta          JSONB NOT NULL DEFAULT '{}'::jsonb
    );
  ");

  // oauth links table
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS oauth_links (
      provider   VARCHAR(32) NOT NULL,
      sub        VARCHAR(255) NOT NULL,
      user_id    TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
      email      VARCHAR(190),
      name       VARCHAR(190),
      linked_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
      PRIMARY KEY (provider, sub)
    );
  ");

  // user sessions table
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS user_sessions (
      sid         VARCHAR(255) PRIMARY KEY,
      user_id     TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
      ip          VARCHAR(128),
      ua          TEXT,
      label       VARCHAR(190),
      created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
      last_seen   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
      revoked_at  TIMESTAMPTZ NULL
    );
  ");

  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_users_created ON users(created_at);");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_oauth_user_id ON oauth_links(user_id);");

  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_sessions_user_id ON user_sessions(user_id);");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_sessions_last_seen ON user_sessions(last_seen);");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_sessions_revoked_at ON user_sessions(revoked_at);");

  // Якщо users колись створився без нових колонок — докрутимо
  $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS password_hash VARCHAR(255);");
  $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS meta JSONB NOT NULL DEFAULT '{}'::jsonb;");
  $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS plan VARCHAR(32) NOT NULL DEFAULT 'free';");
  $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS expires_at TIMESTAMPTZ NULL;");
  $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS paid_at TIMESTAMPTZ NULL;");

  // Якщо user_sessions колись створилась неповною — докрутимо
  $pdo->exec("ALTER TABLE user_sessions ADD COLUMN IF NOT EXISTS ip VARCHAR(128);");
  $pdo->exec("ALTER TABLE user_sessions ADD COLUMN IF NOT EXISTS ua TEXT;");
  $pdo->exec("ALTER TABLE user_sessions ADD COLUMN IF NOT EXISTS label VARCHAR(190);");
  $pdo->exec("ALTER TABLE user_sessions ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT NOW();");
  $pdo->exec("ALTER TABLE user_sessions ADD COLUMN IF NOT EXISTS last_seen TIMESTAMPTZ NOT NULL DEFAULT NOW();");
  $pdo->exec("ALTER TABLE user_sessions ADD COLUMN IF NOT EXISTS revoked_at TIMESTAMPTZ NULL;");
}

function user_generate_id(): string {
  return bin2hex(random_bytes(16));
}

function normalize_iso(?string $v): ?string {
  if ($v === null) return null;

  $v = trim($v);
  if ($v === '' || strtolower($v) === 'null') return null;

  $ts = strtotime($v);
  if ($ts === false) return null;

  return gmdate('c', $ts);
}

function row_to_user(array $row): array {
  $meta = [];

  if (isset($row['meta'])) {
    if (is_array($row['meta'])) {
      $meta = $row['meta'];
    } elseif (is_string($row['meta'])) {
      $j = json_decode($row['meta'], true);
      if (is_array($j)) $meta = $j;
    }
  }

  $u = $meta;

  $u['id'] = (string)($row['id'] ?? ($u['id'] ?? ''));
  $u['email'] = (string)($row['email'] ?? ($u['email'] ?? ''));
  $u['name'] = (string)($row['name'] ?? ($u['name'] ?? ''));
  $u['password_hash'] = (string)($row['password_hash'] ?? ($u['password_hash'] ?? ''));

  $u['plan'] = (string)($row['plan'] ?? ($u['plan'] ?? 'free'));
  $u['expires_at'] = $row['expires_at'] ?? ($u['expires_at'] ?? null);
  $u['paid_at'] = $row['paid_at'] ?? ($u['paid_at'] ?? null);
  $u['created_at'] = (string)($row['created_at'] ?? ($u['created_at'] ?? gmdate('c')));

  $u['expires_at'] = $u['expires_at'] ? normalize_iso((string)$u['expires_at']) : null;
  $u['paid_at'] = $u['paid_at'] ? normalize_iso((string)$u['paid_at']) : null;

  $p = strtolower(trim((string)$u['plan']));
  if ($p === '' || $p === 'null') $p = 'free';
  if (!in_array($p, ['free', 'basic', 'personal', 'dev'], true)) $p = 'free';
  $u['plan'] = $p;

  return $u;
}

function row_to_session(array $row): array {
  return [
    'sid' => (string)($row['sid'] ?? ''),
    'user_id' => (string)($row['user_id'] ?? ''),
    'ip' => (string)($row['ip'] ?? ''),
    'ua' => (string)($row['ua'] ?? ''),
    'label' => (string)($row['label'] ?? ''),
    'created_at' => normalize_iso(isset($row['created_at']) ? (string)$row['created_at'] : '') ?? '',
    'last_seen' => normalize_iso(isset($row['last_seen']) ? (string)$row['last_seen'] : '') ?? '',
    'revoked_at' => isset($row['revoked_at']) && $row['revoked_at'] !== null
      ? normalize_iso((string)$row['revoked_at'])
      : null,
  ];
}

function user_find_by_id(int|string $id): ?array {
  $pdo = dbi();
  $sid = (string)$id;

  $st = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
  $st->execute(['id' => $sid]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  return $row ? row_to_user($row) : null;
}

function user_find_by_email(string $email): ?array {
  $pdo = dbi();
  $email = strtolower(trim($email));
  if ($email === '') return null;

  $st = $pdo->prepare("SELECT * FROM users WHERE LOWER(email)=LOWER(:e) LIMIT 1");
  $st->execute(['e' => $email]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  return $row ? row_to_user($row) : null;
}

function users_all(): array {
  $pdo = dbi();
  $st = $pdo->query("SELECT * FROM users ORDER BY created_at DESC");
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $out = [];
  foreach ($rows as $r) {
    $out[] = row_to_user($r);
  }

  return $out;
}

function user_create(string $email, string $name, string $passwordHash): string {
  $pdo = dbi();
  $email = strtolower(trim($email));
  $name = trim($name);

  if ($email === '') {
    throw new InvalidArgumentException('user_create: empty email');
  }

  $existing = user_find_by_email($email);
  if ($existing) {
    return (string)$existing['id'];
  }

  $id = user_generate_id();

  $st = $pdo->prepare("
    INSERT INTO users (id, email, name, password_hash, plan, meta)
    VALUES (:id, :email, NULLIF(:name,''), :ph, 'free', '{}'::jsonb)
  ");
  $st->execute([
    'id' => $id,
    'email' => $email,
    'name' => $name,
    'ph' => $passwordHash,
  ]);

  return $id;
}

function user_verify_password(array $user, string $pass): bool {
  $hash = (string)($user['password_hash'] ?? '');
  if ($hash === '') return false;
  return password_verify($pass, $hash);
}

/**
 * Приймає повний масив $u і зберігає:
 * - колонки: email,name,password_hash,plan,expires_at,paid_at
 * - все інше у meta (JSONB)
 */
function user_upsert(array $u): array {
  $pdo = dbi();

  $id = (string)($u['id'] ?? '');
  if ($id === '') {
    throw new InvalidArgumentException('user_upsert: empty id');
  }

  $email = strtolower(trim((string)($u['email'] ?? '')));
  $name  = trim((string)($u['name'] ?? ''));
  $ph    = (string)($u['password_hash'] ?? '');

  $plan = strtolower(trim((string)($u['plan'] ?? 'free')));
  if ($plan === '' || $plan === 'null') $plan = 'free';
  if (!in_array($plan, ['free', 'basic', 'personal', 'dev'], true)) $plan = 'free';

  $expiresAt = $u['expires_at'] ?? null;
  $paidAt    = $u['paid_at'] ?? null;

  $expiresIso = $expiresAt ? normalize_iso((string)$expiresAt) : null;
  $paidIso    = $paidAt ? normalize_iso((string)$paidAt) : null;

  $expiresBind = $expiresIso ?? '';
  $paidBind    = $paidIso ?? '';

  $meta = $u;
  unset(
    $meta['id'],
    $meta['email'],
    $meta['name'],
    $meta['password_hash'],
    $meta['plan'],
    $meta['expires_at'],
    $meta['paid_at'],
    $meta['created_at']
  );

  $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($metaJson) || $metaJson === '') $metaJson = '{}';

  $st = $pdo->prepare("
    INSERT INTO users (id, email, name, password_hash, plan, expires_at, paid_at, meta)
    VALUES (
      :id,
      NULLIF(:email,''),
      NULLIF(:name,''),
      NULLIF(:ph,''),
      :plan,
      NULLIF(:expires_at,'')::timestamptz,
      NULLIF(:paid_at,'')::timestamptz,
      :meta::jsonb
    )
    ON CONFLICT (id) DO UPDATE SET
      email = COALESCE(NULLIF(EXCLUDED.email,''), users.email),
      name  = COALESCE(NULLIF(EXCLUDED.name,''), users.name),
      password_hash = COALESCE(NULLIF(EXCLUDED.password_hash,''), users.password_hash),
      plan = EXCLUDED.plan,
      expires_at = COALESCE(EXCLUDED.expires_at, users.expires_at),
      paid_at    = COALESCE(EXCLUDED.paid_at, users.paid_at),
      meta = users.meta || EXCLUDED.meta
    RETURNING *
  ");

  $st->execute([
    'id' => $id,
    'email' => $email,
    'name' => $name,
    'ph' => $ph,
    'plan' => $plan,
    'expires_at' => $expiresBind,
    'paid_at' => $paidBind,
    'meta' => $metaJson,
  ]);

  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ? row_to_user($row) : $u;
}

function user_save(array $u): array {
  return user_upsert($u);
}

function user_update(int|string $id, array $patch): array {
  $existing = user_find_by_id((string)$id) ?? ['id' => (string)$id];
  $merged = array_merge($existing, $patch, ['id' => (string)$id]);
  return user_upsert($merged);
}

function user_delete(int|string $id): bool {
  $pdo = dbi();
  $sid = (string)$id;
  if ($sid === '') return false;

  $st = $pdo->prepare("DELETE FROM users WHERE id = :id");
  $st->execute(['id' => $sid]);

  return $st->rowCount() > 0;
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
  // Для Postgres нічого "ремонтувати" не треба.
  // Лишаємо як no-op для сумісності зі старими викликами.
}

/* =========================
   OAuth helpers
========================= */

function oauth_find(string $provider, string $sub): ?array {
  $pdo = dbi();
  $provider = strtolower(trim($provider));
  $sub = trim($sub);
  if ($provider === '' || $sub === '') return null;

  $st = $pdo->prepare("
    SELECT provider, sub, user_id, email, name, linked_at
    FROM oauth_links
    WHERE provider = :p AND sub = :s
    LIMIT 1
  ");
  $st->execute([
    'p' => $provider,
    's' => $sub,
  ]);

  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function oauth_find_by_email(string $provider, string $email): ?array {
  $pdo = dbi();
  $provider = strtolower(trim($provider));
  $email = strtolower(trim($email));
  if ($provider === '' || $email === '') return null;

  $st = $pdo->prepare("
    SELECT provider, sub, user_id, email, name, linked_at
    FROM oauth_links
    WHERE provider = :p AND LOWER(email) = LOWER(:e)
    ORDER BY linked_at DESC
    LIMIT 1
  ");
  $st->execute([
    'p' => $provider,
    'e' => $email,
  ]);

  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function oauth_user_id_by_provider_sub(string $provider, string $sub): ?string {
  $r = oauth_find($provider, $sub);
  if (!$r) return null;

  $uid = (string)($r['user_id'] ?? '');
  return $uid !== '' ? $uid : null;
}

function oauth_link(string $provider, string $sub, string $userId, string $email = '', string $name = ''): array {
  $pdo = dbi();
  $provider = strtolower(trim($provider));
  $sub = trim($sub);
  $userId = trim($userId);

  if ($provider === '' || $sub === '' || $userId === '') {
    throw new InvalidArgumentException('oauth_link: provider/sub/userId required');
  }

  $u = user_find_by_id($userId);
  if (!$u) {
    user_upsert([
      'id' => $userId,
      'email' => $email,
      'name' => $name,
      'plan' => 'free',
      'created_at' => gmdate('c'),
    ]);
  }

  $st = $pdo->prepare("
    INSERT INTO oauth_links (provider, sub, user_id, email, name)
    VALUES (:p, :s, :uid, NULLIF(:e,''), NULLIF(:n,''))
    ON CONFLICT (provider, sub)
    DO UPDATE SET
      user_id = EXCLUDED.user_id,
      email = COALESCE(NULLIF(EXCLUDED.email,''), oauth_links.email),
      name  = COALESCE(NULLIF(EXCLUDED.name,''), oauth_links.name)
    RETURNING provider, sub, user_id, email, name, linked_at
  ");

  $st->execute([
    'p' => $provider,
    's' => $sub,
    'uid' => $userId,
    'e' => $email,
    'n' => $name,
  ]);

  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: (oauth_find($provider, $sub) ?? []);
}

/* =========================
   Sessions / Devices helpers
========================= */

function client_ip_guess(): string {
  $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];

  foreach ($keys as $k) {
    $v = (string)($_SERVER[$k] ?? '');
    if ($v === '') continue;

    if (strpos($v, ',') !== false) {
      $v = trim(explode(',', $v)[0]);
    }

    return trim($v);
  }

  return '';
}

function session_current_id_safe(): string {
  if (session_status() !== PHP_SESSION_ACTIVE) return '';
  $sid = session_id();
  return is_string($sid) ? $sid : '';
}

/**
 * Якщо поточна сесія була відкликана — розлогінює.
 * Викликай після session_start() і після того, як знаєш user_id.
 */
function session_enforce_not_revoked(string $uid): void {
  $pdo = dbi();
  $uid = trim($uid);
  if ($uid === '') return;

  $sid = session_current_id_safe();
  if ($sid === '') return;

  $st = $pdo->prepare("
    SELECT sid, revoked_at
    FROM user_sessions
    WHERE sid = :sid AND user_id = :uid
    LIMIT 1
  ");
  $st->execute([
    'sid' => $sid,
    'uid' => $uid,
  ]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  if ($row && !empty($row['revoked_at'])) {
    $_SESSION = [];

    if (session_status() === PHP_SESSION_ACTIVE) {
      @session_destroy();
    }

    if (ini_get('session.use_cookies')) {
      $params = session_get_cookie_params();
      @setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
    }

    header('Location: /login?reason=session_revoked', true, 302);
    exit;
  }
}

/**
 * Реєструє або оновлює поточну сесію користувача.
 * Викликай після логіну і на авторизованих сторінках.
 */
function session_register_current(string $uid, string $label = ''): void {
  $pdo = dbi();
  $uid = trim($uid);
  if ($uid === '') return;
  if (session_status() !== PHP_SESSION_ACTIVE) return;

  $sid = session_current_id_safe();
  if ($sid === '') return;

  $ip = client_ip_guess();
  $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

  $st = $pdo->prepare("
    INSERT INTO user_sessions (sid, user_id, ip, ua, label, created_at, last_seen, revoked_at)
    VALUES (
      :sid,
      :uid,
      NULLIF(:ip,''),
      NULLIF(:ua,''),
      NULLIF(:label,''),
      NOW(),
      NOW(),
      NULL
    )
    ON CONFLICT (sid) DO UPDATE SET
      user_id = EXCLUDED.user_id,
      ip = COALESCE(NULLIF(EXCLUDED.ip,''), user_sessions.ip),
      ua = COALESCE(NULLIF(EXCLUDED.ua,''), user_sessions.ua),
      label = CASE
        WHEN NULLIF(EXCLUDED.label,'') IS NOT NULL THEN EXCLUDED.label
        ELSE user_sessions.label
      END,
      last_seen = NOW(),
      revoked_at = NULL
  ");
  $st->execute([
    'sid' => $sid,
    'uid' => $uid,
    'ip' => $ip,
    'ua' => $ua,
    'label' => $label,
  ]);
}

/**
 * При logout прибрати поточну сесію зі списку активних.
 */
function session_unregister_current_for_user(string $uid): void {
  $pdo = dbi();
  $uid = trim($uid);
  if ($uid === '') return;

  $sid = session_current_id_safe();
  if ($sid === '') return;

  $st = $pdo->prepare("DELETE FROM user_sessions WHERE sid = :sid AND user_id = :uid");
  $st->execute([
    'sid' => $sid,
    'uid' => $uid,
  ]);
}

/**
 * Повертає список активних сесій користувача.
 */
function sessions_list_for_user(string $uid): array {
  $pdo = dbi();
  $uid = trim($uid);
  if ($uid === '') return [];

  $st = $pdo->prepare("
    SELECT sid, user_id, ip, ua, label, created_at, last_seen, revoked_at
    FROM user_sessions
    WHERE user_id = :uid
      AND revoked_at IS NULL
    ORDER BY last_seen DESC, created_at DESC
  ");
  $st->execute(['uid' => $uid]);

  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  $out = [];

  foreach ($rows as $row) {
    $out[] = row_to_session($row);
  }

  return $out;
}

/**
 * Відкликає конкретну сесію.
 */
function session_revoke_for_user(string $uid, string $sid): void {
  $pdo = dbi();
  $uid = trim($uid);
  $sid = trim($sid);
  if ($uid === '' || $sid === '') return;

  $st = $pdo->prepare("
    UPDATE user_sessions
    SET revoked_at = NOW()
    WHERE sid = :sid AND user_id = :uid
  ");
  $st->execute([
    'sid' => $sid,
    'uid' => $uid,
  ]);
}

/**
 * Відкликає всі сесії користувача, опційно крім однієї.
 */
function sessions_revoke_all_for_user(string $uid, ?string $exceptSid = null): void {
  $pdo = dbi();
  $uid = trim($uid);
  if ($uid === '') return;

  $exceptSid = $exceptSid !== null ? trim($exceptSid) : null;

  if ($exceptSid !== null && $exceptSid !== '') {
    $st = $pdo->prepare("
      UPDATE user_sessions
      SET revoked_at = NOW()
      WHERE user_id = :uid
        AND revoked_at IS NULL
        AND sid <> :except_sid
    ");
    $st->execute([
      'uid' => $uid,
      'except_sid' => $exceptSid,
    ]);
  } else {
    $st = $pdo->prepare("
      UPDATE user_sessions
      SET revoked_at = NOW()
      WHERE user_id = :uid
        AND revoked_at IS NULL
    ");
    $st->execute([
      'uid' => $uid,
    ]);
  }
}

/**
 * Повністю видалити всі сліди сесій користувача.
 */
function sessions_delete_user_all(string $uid): void {
  $pdo = dbi();
  $uid = trim($uid);
  if ($uid === '') return;

  $st = $pdo->prepare("DELETE FROM user_sessions WHERE user_id = :uid");
  $st->execute(['uid' => $uid]);
}