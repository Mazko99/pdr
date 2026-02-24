<?php
// src/db.php
declare(strict_types=1);

function db(): PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;

  // ✅ підстав свої значення (або винеси в env / config.php)
  $host = getenv('DB_HOST') ?: '127.0.0.1';
  $name = getenv('DB_NAME') ?: 'prostopdr';
  $user = getenv('DB_USER') ?: 'root';
  $pass = getenv('DB_PASS') ?: '';
  $charset = 'utf8mb4';

  $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";
  $opt = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ];
  $pdo = new PDO($dsn, $user, $pass, $opt);
  return $pdo;
}
