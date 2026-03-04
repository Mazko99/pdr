<?php
declare(strict_types=1);

/**
 * Postgres DB helper for ProstoPDR.
 *
 * ✅ Important: some projects already declare db() in bootstrap.php.
 * To avoid "Cannot redeclare db()", we declare db() only if it's not declared yet.
 *
 * Supports:
 * - DATABASE_URL (Railway style)  OR
 * - PHP_DB_HOST/PORT/NAME/USER/PASS (+ PHP_DB_DRIVER=pgsql)
 */

if (!function_exists('db')) {

  function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    // 1) Railway standard env
    $databaseUrl = (string)(getenv('DATABASE_URL') ?: '');

    // 2) Custom envs (your style)
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
      // Example: postgres://user:pass@host:port/dbname
      $parts = parse_url($databaseUrl);
      if (!is_array($parts)) {
        throw new RuntimeException('Invalid DATABASE_URL');
      }

      $h = (string)($parts['host'] ?? '');
      $p = (string)($parts['port'] ?? '5432');
      $db = (string)($parts['path'] ?? '');
      $db = ltrim($db, '/');

      $u = (string)($parts['user'] ?? '');
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

      // allow Railway PG* variables if you used references but named differently
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
    ]);

    ensure_schema($pdo);
    return $pdo;
  }

  function ensure_schema(PDO $pdo): void {
    // Base table
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

    // If you already had an old users table, safely add missing columns
    $cols = [
      "password_hash VARCHAR(255)",
      "google_sub VARCHAR(255) UNIQUE",
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
      // extract column name
      $name = preg_split('/\s+/', trim($def))[0] ?? '';
      if ($name === '') continue;
      try {
        $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS {$def};");
      } catch (Throwable $e) {
        // ignore (some defs like UNIQUE may fail if column exists differently)
      }
    }
  }

  // --- simple queries you can use in users_store.php ---

  function db_find_user_by_id(PDO $pdo, string $id): ?array {
    $st = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $st->execute(['id' => $id]);
    $row = $st->fetch();
    return $row ?: null;
  }

  function db_find_user_by_email(PDO $pdo, string $email): ?array {
    $st = $pdo->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
    $st->execute(['email' => $email]);
    $row = $st->fetch();
    return $row ?: null;
  }

  function db_find_user_by_google_sub(PDO $pdo, string $sub): ?array {
    $st = $pdo->prepare("SELECT * FROM users WHERE google_sub = :sub LIMIT 1");
    $st->execute(['sub' => $sub]);
    $row = $st->fetch();
    return $row ?: null;
  }

  function db_upsert_user(PDO $pdo, array $u): void {
    // expects $u['id'] present
    $id = (string)($u['id'] ?? '');
    if ($id === '') throw new RuntimeException('db_upsert_user: missing id');

    // normalize known fields
    $fields = [
      'id','email','name','password_hash','google_sub',
      'plan','expires_at',
      'trial_used','trial_started_at','trial_expires_at','trial_cancelled',
      'paid_at','plan_set_at','mono_last_payment_at',
      'buy_pending_invoice','buy_pending_plan','trial_pending_plan'
    ];

    $data = [];
    foreach ($fields as $f) {
      $data[$f] = $u[$f] ?? null;
    }

    $sql = "
      INSERT INTO users (
        id,email,name,password_hash,google_sub,
        plan,expires_at,
        trial_used,trial_started_at,trial_expires_at,trial_cancelled,
        paid_at,plan_set_at,mono_last_payment_at,
        buy_pending_invoice,buy_pending_plan,trial_pending_plan
      ) VALUES (
        :id,:email,:name,:password_hash,:google_sub,
        :plan,:expires_at,
        :trial_used,:trial_started_at,:trial_expires_at,:trial_cancelled,
        :paid_at,:plan_set_at,:mono_last_payment_at,
        :buy_pending_invoice,:buy_pending_plan,:trial_pending_plan
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
    $st->execute($data);
  }

}