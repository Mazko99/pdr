<?php
declare(strict_types=1);

/**
 * Postgres DB helper for ProstoPDR.
 *
 * ✅ Important: some projects already declare db() in bootstrap.php.
 * To avoid "Cannot redeclare db()", we declare db() only if it's not declared yet.
 *
 * Supports:
 * - DATABASE_URL (Railway style)
 * - or PHP_DB_HOST / PHP_DB_PORT / PHP_DB_NAME / PHP_DB_USER / PHP_DB_PASS
 * - fallback to PGHOST / PGPORT / PGDATABASE / PGUSER / PGPASSWORD
 */

if (!function_exists('db')) {

  function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
      return $pdo;
    }

    $databaseUrl = (string)(getenv('DATABASE_URL') ?: '');

    $driver = (string)(getenv('PHP_DB_DRIVER') ?: 'pgsql');

    $host = (string)(getenv('PHP_DB_HOST') ?: '');
    $port = (string)(getenv('PHP_DB_PORT') ?: '');
    $name = (string)(getenv('PHP_DB_NAME') ?: '');
    $user = (string)(getenv('PHP_DB_USER') ?: '');
    $pass = (string)(getenv('PHP_DB_PASS') ?: '');

    $dsn = '';
    $dsnUser = '';
    $dsnPass = '';

    if ($databaseUrl !== '') {
      $parts = parse_url($databaseUrl);
      if (!is_array($parts)) {
        throw new RuntimeException('Invalid DATABASE_URL');
      }

      $h  = (string)($parts['host'] ?? '');
      $p  = (string)($parts['port'] ?? '5432');
      $db = ltrim((string)($parts['path'] ?? ''), '/');
      $u  = (string)($parts['user'] ?? '');
      $pw = (string)($parts['pass'] ?? '');

      if ($h === '' || $db === '') {
        throw new RuntimeException('DATABASE_URL missing host or dbname');
      }

      $dsn = "pgsql:host={$h};port={$p};dbname={$db}";
      $dsnUser = $u;
      $dsnPass = $pw;
    } else {
      if ($driver !== 'pgsql') {
        throw new RuntimeException('Set PHP_DB_DRIVER=pgsql (or set DATABASE_URL)');
      }

      if ($host === '') $host = (string)(getenv('PGHOST') ?: '127.0.0.1');
      if ($port === '') $port = (string)(getenv('PGPORT') ?: '5432');
      if ($name === '') $name = (string)(getenv('PGDATABASE') ?: 'app');
      if ($user === '') $user = (string)(getenv('PGUSER') ?: 'app');
      if ($pass === '') $pass = (string)(getenv('PGPASSWORD') ?: 'app');

      $dsn = "pgsql:host={$host};port={$port};dbname={$name}";
      $dsnUser = $user;
      $dsnPass = $pass;
    }

    $pdo = new PDO($dsn, $dsnUser, $dsnPass, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    try {
      $pdo->exec("SET TIME ZONE 'UTC'");
    } catch (Throwable $e) {
      // ignore
    }

    ensure_schema($pdo);

    return $pdo;
  }

  function pdoi(): PDO {
    return db();
  }

  function ensure_schema(PDO $pdo): void {
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS users (
        id TEXT PRIMARY KEY,
        email VARCHAR(190) NOT NULL UNIQUE,
        name VARCHAR(190),
        password_hash VARCHAR(255),
        google_sub VARCHAR(255) UNIQUE,
        plan VARCHAR(50) NOT NULL DEFAULT 'free',
        expires_at TIMESTAMPTZ NULL,
        trial_used BOOLEAN NOT NULL DEFAULT FALSE,
        trial_started_at TIMESTAMPTZ NULL,
        trial_expires_at TIMESTAMPTZ NULL,
        trial_cancelled BOOLEAN NOT NULL DEFAULT FALSE,
        paid_at TIMESTAMPTZ NULL,
        plan_set_at TIMESTAMPTZ NULL,
        mono_last_payment_at TIMESTAMPTZ NULL,
        buy_pending_invoice TEXT NULL,
        buy_pending_plan VARCHAR(50) NULL,
        trial_pending_plan VARCHAR(50) NULL,
        created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
      );
    ");

    $cols = [
      "password_hash VARCHAR(255)",
      "google_sub VARCHAR(255)",
      "plan VARCHAR(50) NOT NULL DEFAULT 'free'",
      "expires_at TIMESTAMPTZ NULL",
      "trial_used BOOLEAN NOT NULL DEFAULT FALSE",
      "trial_started_at TIMESTAMPTZ NULL",
      "trial_expires_at TIMESTAMPTZ NULL",
      "trial_cancelled BOOLEAN NOT NULL DEFAULT FALSE",
      "paid_at TIMESTAMPTZ NULL",
      "plan_set_at TIMESTAMPTZ NULL",
      "mono_last_payment_at TIMESTAMPTZ NULL",
      "buy_pending_invoice TEXT NULL",
      "buy_pending_plan VARCHAR(50) NULL",
      "trial_pending_plan VARCHAR(50) NULL",
      "created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()",
    ];

    foreach ($cols as $def) {
      $col = preg_split('/\s+/', trim($def))[0] ?? '';
      if ($col === '') continue;

      try {
        $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS {$def};");
      } catch (Throwable $e) {
        // ignore
      }
    }

    try {
      $pdo->exec("CREATE INDEX IF NOT EXISTS idx_users_created_at ON users (created_at DESC);");
    } catch (Throwable $e) {
      // ignore
    }

    try {
      $pdo->exec("CREATE INDEX IF NOT EXISTS idx_users_paid_at ON users (paid_at DESC);");
    } catch (Throwable $e) {
      // ignore
    }

    try {
      $pdo->exec("CREATE INDEX IF NOT EXISTS idx_users_expires_at ON users (expires_at DESC);");
    } catch (Throwable $e) {
      // ignore
    }
  }

  function db_find_user_by_id(PDO $pdo, string $id): ?array {
    $st = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    return is_array($row) ? $row : null;
  }

  function db_find_user_by_email(PDO $pdo, string $email): ?array {
    $st = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1");
    $st->execute([':email' => $email]);
    $row = $st->fetch();
    return is_array($row) ? $row : null;
  }

  function db_find_user_by_google_sub(PDO $pdo, string $sub): ?array {
    $st = $pdo->prepare("SELECT * FROM users WHERE google_sub = :sub LIMIT 1");
    $st->execute([':sub' => $sub]);
    $row = $st->fetch();
    return is_array($row) ? $row : null;
  }

  function db_all_users(PDO $pdo): array {
    $st = $pdo->query("
      SELECT
        id, email, name, password_hash, google_sub,
        plan, expires_at,
        trial_used, trial_started_at, trial_expires_at, trial_cancelled,
        paid_at, plan_set_at, mono_last_payment_at,
        buy_pending_invoice, buy_pending_plan, trial_pending_plan,
        created_at
      FROM users
      ORDER BY created_at DESC NULLS LAST, id DESC
    ");
    $rows = $st->fetchAll();
    return is_array($rows) ? $rows : [];
  }

  function db_upsert_user(PDO $pdo, array $u): void {
    $id = (string)($u['id'] ?? '');
    if ($id === '') {
      throw new RuntimeException('db_upsert_user: missing id');
    }

    $sql = "
      INSERT INTO users (
        id, email, name, password_hash, google_sub,
        plan, expires_at,
        trial_used, trial_started_at, trial_expires_at, trial_cancelled,
        paid_at, plan_set_at, mono_last_payment_at,
        buy_pending_invoice, buy_pending_plan, trial_pending_plan,
        created_at
      ) VALUES (
        :id, :email, :name, :password_hash, :google_sub,
        :plan, :expires_at,
        :trial_used, :trial_started_at, :trial_expires_at, :trial_cancelled,
        :paid_at, :plan_set_at, :mono_last_payment_at,
        :buy_pending_invoice, :buy_pending_plan, :trial_pending_plan,
        :created_at
      )
      ON CONFLICT (id) DO UPDATE SET
        email = EXCLUDED.email,
        name = EXCLUDED.name,
        password_hash = EXCLUDED.password_hash,
        google_sub = EXCLUDED.google_sub,
        plan = EXCLUDED.plan,
        expires_at = EXCLUDED.expires_at,
        trial_used = EXCLUDED.trial_used,
        trial_started_at = EXCLUDED.trial_started_at,
        trial_expires_at = EXCLUDED.trial_expires_at,
        trial_cancelled = EXCLUDED.trial_cancelled,
        paid_at = EXCLUDED.paid_at,
        plan_set_at = EXCLUDED.plan_set_at,
        mono_last_payment_at = EXCLUDED.mono_last_payment_at,
        buy_pending_invoice = EXCLUDED.buy_pending_invoice,
        buy_pending_plan = EXCLUDED.buy_pending_plan,
        trial_pending_plan = EXCLUDED.trial_pending_plan
    ";

    $st = $pdo->prepare($sql);
    $st->execute([
      ':id' => $u['id'],
      ':email' => $u['email'] !== '' ? $u['email'] : null,
      ':name' => $u['name'] !== '' ? $u['name'] : null,
      ':password_hash' => $u['password_hash'] !== '' ? $u['password_hash'] : null,
      ':google_sub' => $u['google_sub'] ?? null,

      ':plan' => $u['plan'] ?? 'free',
      ':expires_at' => $u['expires_at'] ?? null,

      ':trial_used' => $u['trial_used'] ?? false,
      ':trial_started_at' => $u['trial_started_at'] ?? null,
      ':trial_expires_at' => $u['trial_expires_at'] ?? null,
      ':trial_cancelled' => $u['trial_cancelled'] ?? false,

      ':paid_at' => $u['paid_at'] ?? null,
      ':plan_set_at' => $u['plan_set_at'] ?? null,
      ':mono_last_payment_at' => $u['mono_last_payment_at'] ?? null,

      ':buy_pending_invoice' => $u['buy_pending_invoice'] ?? null,
      ':buy_pending_plan' => $u['buy_pending_plan'] ?? null,
      ':trial_pending_plan' => $u['trial_pending_plan'] ?? null,

      ':created_at' => $u['created_at'] ?? gmdate('c'),
    ]);
  }

  function db_delete_user(PDO $pdo, string $id): bool {
    $st = $pdo->prepare("DELETE FROM users WHERE id = :id");
    $st->execute([':id' => $id]);
    return $st->rowCount() > 0;
  }
}