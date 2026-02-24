<?php
declare(strict_types=1);

function db(): PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;

  $driver = getenv('PHP_DB_DRIVER') ?: 'pgsql';
  if ($driver !== 'pgsql') {
    throw new RuntimeException('Set PHP_DB_DRIVER=pgsql in .env');
  }

  $host = getenv('PHP_DB_HOST') ?: '127.0.0.1';
  $port = getenv('PHP_DB_PORT') ?: '5432';
  $name = getenv('PHP_DB_NAME') ?: 'app';
  $user = getenv('PHP_DB_USER') ?: 'app';
  $pass = getenv('PHP_DB_PASS') ?: 'app';

  $dsn = "pgsql:host={$host};port={$port};dbname={$name}";

  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);

  ensure_schema($pdo);
  return $pdo;
}

function ensure_schema(PDO $pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
      id BIGSERIAL PRIMARY KEY,
      email VARCHAR(190) NOT NULL UNIQUE,
      name VARCHAR(190),
      password_hash VARCHAR(255),
      google_sub VARCHAR(255) UNIQUE,
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    );
  ");
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

function db_create_user_email(PDO $pdo, string $email, string $name, string $passwordHash): int {
  $st = $pdo->prepare("
    INSERT INTO users (email, name, password_hash)
    VALUES (:email, :name, :ph)
    RETURNING id
  ");
  $st->execute(['email' => $email, 'name' => ($name !== '' ? $name : null), 'ph' => $passwordHash]);
  return (int)$st->fetchColumn();
}

function db_upsert_user_google(PDO $pdo, string $email, string $name, string $sub): int {
  $u = db_find_user_by_google_sub($pdo, $sub);
  if ($u) return (int)$u['id'];

  $u2 = db_find_user_by_email($pdo, $email);
  if ($u2) {
    $st = $pdo->prepare("
      UPDATE users
      SET google_sub = :sub,
          name = COALESCE(NULLIF(name,''), :name)
      WHERE id = :id
      RETURNING id
    ");
    $st->execute(['sub' => $sub, 'name' => ($name !== '' ? $name : null), 'id' => (int)$u2['id']]);
    return (int)$st->fetchColumn();
  }

  $st = $pdo->prepare("
    INSERT INTO users (email, name, google_sub)
    VALUES (:email, :name, :sub)
    RETURNING id
  ");
  $st->execute(['email' => $email, 'name' => ($name !== '' ? $name : null), 'sub' => $sub]);
  return (int)$st->fetchColumn();
}
