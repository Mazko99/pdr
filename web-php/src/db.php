<?php
declare(strict_types=1);

/**
 * src/db.php
 * PDO Postgres connection helper for Railway DATABASE_URL.
 */

if (!function_exists('db')) {

  function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $url = (string)(getenv('DATABASE_URL') ?: '');
    if ($url === '') {
      throw new RuntimeException('DATABASE_URL is empty. Set it in Railway (pdr service variables) as {{ Postgres.DATABASE_URL }}');
    }

    $parts = parse_url($url);
    if (!is_array($parts)) {
      throw new RuntimeException('DATABASE_URL parse failed');
    }

    $host = (string)($parts['host'] ?? '');
    $port = (string)($parts['port'] ?? '5432');
    $user = (string)($parts['user'] ?? '');
    $pass = (string)($parts['pass'] ?? '');
    $dbn  = ltrim((string)($parts['path'] ?? ''), '/');

    if ($host === '' || $user === '' || $dbn === '') {
      throw new RuntimeException('DATABASE_URL missing host/user/dbname');
    }

    // Railway Postgres часто вимагає SSL
    $dsn = "pgsql:host={$host};port={$port};dbname={$dbn};sslmode=require";

    $pdo = new PDO($dsn, $user, $pass, [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
  }

}