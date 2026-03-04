<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * users_store.php (Postgres edition)
 *
 * Keeps your existing API:
 * - users_all()
 * - user_find_by_id()
 * - user_update()
 * - user_save()
 * - user_has_access()
 * - oauth_link(), oauth_user_id_by_provider_sub(), etc.
 *
 * Table design: id is TEXT (because your ids look like hex strings).
 */

function ensure_schema(PDO $pdo): void {
  // users
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
      id           TEXT PRIMARY KEY,
      email        VARCHAR(190) UNIQUE,
      name         VARCHAR(190),
      password_hash VARCHAR(255),
      plan         VARCHAR(32) NOT NULL DEFAULT 'free',
      paid_at      TIMESTAMPTZ NULL,
      expires_at   TIMESTAMPTZ NULL,
      created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
    );
  ");

  // oauth links (google/apple)
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

  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_oauth_user_id ON oauth_links(user_id);");
}

function dbi(): PDO {
  $pdo = db();
  ensure_schema($pdo);
  return $pdo;
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

function users_all(): array {
  $pdo = dbi();
  $st = $pdo->query("SELECT id,email,name,password_hash,plan,paid_at,expires_at,created_at FROM users ORDER BY created_at DESC");
  $rows = $st->fetchAll();
  $out = [];
  foreach ($rows as $r) $out[] = user_normalize($r);
  return $out;
}

function user_find_by_id(int|string $id): ?array {
  $pdo = dbi();
  $sid = (string)$id;
  $st = $pdo->prepare("SELECT id,email,name,password_hash,plan,paid_at,expires_at,created_at FROM users WHERE id = :id LIMIT 1");
  $st->execute(['id' => $sid]);
  $row = $st->fetch();
  return $row ? user_normalize($row) : null;
}

function user_find_by_email(string $email): ?array {
  $pdo = dbi();
  $st = $pdo->prepare("SELECT id,email,name,password_hash,plan,paid_at,expires_at,created_at FROM users WHERE LOWER(email)=LOWER(:e) LIMIT 1");
  $st->execute(['e' => $email]);
  $row = $st->fetch();
  return $row ? user_normalize($row) : null;
}

/**
 * Update user by id (create if missing).
 * This is used by your payment webhook too.
 */
function user_update(int|string $id, array $patch): array {
  $pdo = dbi();
  $sid = (string)$id;

  $existing = user_find_by_id($sid);

  // prepare values
  $email = array_key_exists('email', $patch) ? (string)$patch['email'] : ($existing['email'] ?? '');
  $name  = array_key_exists('name', $patch) ? (string)$patch['name'] : ($existing['name'] ?? '');
  $ph    = array_key_exists('password_hash', $patch) ? (string)$patch['password_hash'] : ($existing['password_hash'] ?? '');

  $plan  = array_key_exists('plan', $patch) ? strtolower(trim((string)$patch['plan'])) : ($existing['plan'] ?? 'free');
  if ($plan === '') $plan = 'free';

  $paidAt    = array_key_exists('paid_at', $patch) ? $patch['paid_at'] : ($existing['paid_at'] ?? null);
  $expiresAt = array_key_exists('expires_at', $patch) ? $patch['expires_at'] : ($existing['expires_at'] ?? null);

  // normalize timestamps to null|string (ISO)
  $paidAt = ($paidAt === '' || $paidAt === 'null') ? null : $paidAt;
  $expiresAt = ($expiresAt === '' || $expiresAt === 'null') ? null : $expiresAt;

  if ($existing) {
    $st = $pdo->prepare("
      UPDATE users
      SET email = NULLIF(:email,''),
          name  = NULLIF(:name,''),
          password_hash = NULLIF(:ph,''),
          plan = :plan,
          paid_at = CASE WHEN :paid_at IS NULL THEN paid_at ELSE :paid_at::timestamptz END,
          expires_at = CASE WHEN :expires_at IS NULL THEN expires_at ELSE :expires_at::timestamptz END
      WHERE id = :id
      RETURNING id,email,name,password_hash,plan,paid_at,expires_at,created_at
    ");
    $st->execute([
      'id' => $sid,
      'email' => $email,
      'name' => $name,
      'ph' => $ph,
      'plan' => $plan,
      'paid_at' => $paidAt,
      'expires_at' => $expiresAt,
    ]);
    $row = $st->fetch();
    return $row ? user_normalize($row) : (user_find_by_id($sid) ?? user_normalize(['id'=>$sid]));
  }

  // create
  $st = $pdo->prepare("
    INSERT INTO users (id,email,name,password_hash,plan,paid_at,expires_at)
    VALUES (:id, NULLIF(:email,''), NULLIF(:name,''), NULLIF(:ph,''), :plan,
            CASE WHEN :paid_at IS NULL THEN NULL ELSE :paid_at::timestamptz END,
            CASE WHEN :expires_at IS NULL THEN NULL ELSE :expires_at::timestamptz END)
    RETURNING id,email,name,password_hash,plan,paid_at,expires_at,created_at
  ");
  $st->execute([
    'id' => $sid,
    'email' => $email,
    'name' => $name,
    'ph' => $ph,
    'plan' => $plan,
    'paid_at' => $paidAt,
    'expires_at' => $expiresAt,
  ]);
  $row = $st->fetch();
  return $row ? user_normalize($row) : (user_find_by_id($sid) ?? user_normalize(['id'=>$sid]));
}

function user_save(array $user): array {
  $id = (string)($user['id'] ?? '');
  if ($id === '') throw new InvalidArgumentException('user_save: empty id');
  return user_update($id, $user);
}

function user_has_access(array $user, ?int $nowTs = null): bool {
  $plan = strtolower((string)($user['plan'] ?? 'free'));
  if ($plan === 'dev') return true;
  if ($plan !== 'basic' && $plan !== 'personal' && $plan !== 'mini12' && $plan !== '12d' && $plan !== 'base') return false;

  $exp = $user['expires_at'] ?? null;
  if ($exp === null || $exp === '' || $exp === 'null') return true;

  $ts = strtotime((string)$exp);
  if ($ts === false) return true;

  $now = $nowTs ?? time();
  return $ts > $now;
}

/* =========================
   OAUTH (Google/Apple)
========================= */

function oauth_find(string $provider, string $sub): ?array {
  $pdo = dbi();
  $provider = strtolower(trim($provider));
  $sub = trim($sub);
  if ($provider === '' || $sub === '') return null;

  $st = $pdo->prepare("SELECT provider,sub,user_id,email,name,linked_at FROM oauth_links WHERE provider=:p AND sub=:s LIMIT 1");
  $st->execute(['p'=>$provider,'s'=>$sub]);
  $row = $st->fetch();
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

  // ensure user exists (at least stub)
  if (!user_find_by_id($userId)) {
    user_update($userId, [
      'email' => $email,
      'name' => $name,
      'plan' => 'free',
      'created_at' => gmdate('c'),
    ]);
  }

  $st = $pdo->prepare("
    INSERT INTO oauth_links (provider, sub, user_id, email, name)
    VALUES (:p,:s,:uid, NULLIF(:e,''), NULLIF(:n,''))
    ON CONFLICT (provider, sub)
    DO UPDATE SET user_id = EXCLUDED.user_id,
                 email = COALESCE(NULLIF(EXCLUDED.email,''), oauth_links.email),
                 name  = COALESCE(NULLIF(EXCLUDED.name,''),  oauth_links.name)
    RETURNING provider,sub,user_id,email,name,linked_at
  ");
  $st->execute([
    'p'=>$provider,'s'=>$sub,'uid'=>$userId,'e'=>$email,'n'=>$name
  ]);
  $row = $st->fetch();
  return $row ?: (oauth_find($provider,$sub) ?? []);
}