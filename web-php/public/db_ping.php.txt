<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/db.php';

header('Content-Type: text/plain; charset=utf-8');

echo "ENV DATABASE_URL set: " . (getenv('DATABASE_URL') ? "YES\n" : "NO\n");

try {
  $pdo = db();
  echo "DB CONNECT: OK\n";

  $pdo->exec("INSERT INTO users (id, email, name, plan) VALUES ('ping_user', 'ping@local', 'Ping', 'free')
              ON CONFLICT (id) DO UPDATE SET name=EXCLUDED.name");

  $c = $pdo->query("SELECT COUNT(*)::int AS c FROM users")->fetch();
  echo "USERS COUNT = " . ($c['c'] ?? '??') . "\n";

  $one = $pdo->query("SELECT id,email,plan,created_at FROM users WHERE id='ping_user'")->fetch();
  echo "PING ROW = " . json_encode($one, JSON_UNESCAPED_UNICODE) . "\n";

} catch (Throwable $e) {
  echo "DB CONNECT: FAIL\n";
  echo $e->getMessage() . "\n";
}