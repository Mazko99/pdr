<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * sessions_store.php (Postgres)
 * Зберігаємо активні сесії користувачів у БД.
 *
 * Таблиця: user_sessions
 * - sid (session_id)
 * - user_id
 * - ua, ip
 * - created_at, last_seen
 * - revoked_at
 */

function sessions_ensure_schema(PDO $pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS user_sessions (
      sid        TEXT PRIMARY KEY,
      user_id    TEXT NOT NULL,
      ua         TEXT NOT NULL DEFAULT '',
      ip         TEXT NOT NULL DEFAULT '',
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
      last_seen  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
      revoked_at TIMESTAMPTZ NULL
    );
  ");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_sessions_user_id ON user_sessions(user_id);");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_sessions_last_seen ON user_sessions(last_seen);");
}

function sessions_pdo(): PDO {
  $pdo = db();
  sessions_ensure_schema($pdo);
  return $pdo;
}

function sessions_client_ip(): string {
  $ip = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
  if ($ip !== '') {
    $parts = explode(',', $ip);
    return trim((string)($parts[0] ?? ''));
  }
  return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

function session_register_current(string $userId): void {
  if (session_status() !== PHP_SESSION_ACTIVE) return;
  $sid = session_id();
  if ($sid === '') return;

  $pdo = sessions_pdo();
  $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
  $ip = sessions_client_ip();

  $stmt = $pdo->prepare("
    INSERT INTO user_sessions (sid, user_id, ua, ip)
    VALUES (:sid, :uid, :ua, :ip)
    ON CONFLICT (sid) DO UPDATE
      SET user_id = EXCLUDED.user_id,
          ua = EXCLUDED.ua,
          ip = EXCLUDED.ip,
          last_seen = NOW(),
          revoked_at = NULL
  ");
  $stmt->execute([
    ':sid' => $sid,
    ':uid' => $userId,
    ':ua'  => $ua,
    ':ip'  => $ip,
  ]);
}

function session_touch_current(string $userId): void {
  if (session_status() !== PHP_SESSION_ACTIVE) return;
  $sid = session_id();
  if ($sid === '') return;

  $pdo = sessions_pdo();
  $stmt = $pdo->prepare("
    UPDATE user_sessions
       SET last_seen = NOW()
     WHERE sid = :sid AND user_id = :uid AND revoked_at IS NULL
  ");
  $stmt->execute([':sid' => $sid, ':uid' => $userId]);
}

function sessions_list_for_user(string $userId): array {
  $pdo = sessions_pdo();
  $stmt = $pdo->prepare("
    SELECT sid AS id, ua, ip, last_seen
      FROM user_sessions
     WHERE user_id = :uid AND revoked_at IS NULL
     ORDER BY last_seen DESC
  ");
  $stmt->execute([':uid' => $userId]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  return is_array($rows) ? $rows : [];
}

function sessions_revoke(string $sid): void {
  $pdo = sessions_pdo();
  $stmt = $pdo->prepare("UPDATE user_sessions SET revoked_at = NOW() WHERE sid = :sid");
  $stmt->execute([':sid' => $sid]);
}

function session_revoke_for_user(string $userId, string $sid): void {
  $pdo = sessions_pdo();
  $stmt = $pdo->prepare("
    UPDATE user_sessions
       SET revoked_at = NOW()
     WHERE sid = :sid AND user_id = :uid
  ");
  $stmt->execute([':sid' => $sid, ':uid' => $userId]);
}

function sessions_revoke_all_for_user(string $userId, ?string $exceptSid = null): void {
  $pdo = sessions_pdo();
  if ($exceptSid) {
    $stmt = $pdo->prepare("
      UPDATE user_sessions
         SET revoked_at = NOW()
       WHERE user_id = :uid AND sid <> :sid AND revoked_at IS NULL
    ");
    $stmt->execute([':uid' => $userId, ':sid' => $exceptSid]);
  } else {
    $stmt = $pdo->prepare("
      UPDATE user_sessions
         SET revoked_at = NOW()
       WHERE user_id = :uid AND revoked_at IS NULL
    ");
    $stmt->execute([':uid' => $userId]);
  }
}