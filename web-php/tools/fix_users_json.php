<?php
declare(strict_types=1);

/**
 * Запуск:
 * php tools/fix_users_json.php
 *
 * Робить backup users.json -> users.json.bak_YYYYmmdd_His
 * Лишає тільки структуру: { "users": [ ... ] }
 */

$path = __DIR__ . '/../storage/users.json';
if (!is_file($path)) {
  fwrite(STDERR, "users.json not found: $path\n");
  exit(1);
}

$raw = file_get_contents($path);
if ($raw === false) {
  fwrite(STDERR, "cannot read: $path\n");
  exit(1);
}

$data = json_decode($raw, true);
if (!is_array($data)) {
  fwrite(STDERR, "invalid JSON\n");
  exit(1);
}

$users = [];

// 1) якщо є users — беремо тільки його
if (isset($data['users']) && is_array($data['users'])) {
  foreach ($data['users'] as $u) {
    if (is_array($u) && !empty($u['id'])) $users[] = $u;
  }
} else {
  // 2) fallback: якщо файл це list
  $isList = array_keys($data) === range(0, count($data) - 1);
  if ($isList) {
    foreach ($data as $u) {
      if (is_array($u) && !empty($u['id'])) $users[] = $u;
    }
  } else {
    // 3) fallback: якщо map id=>user
    foreach ($data as $k => $u) {
      if (is_array($u) && !empty($u['id'])) $users[] = $u;
    }
  }
}

// прибираємо дублікати по id
$uniq = [];
$out = [];
foreach ($users as $u) {
  $id = (string)$u['id'];
  if (!isset($uniq[$id])) {
    $uniq[$id] = true;
    $out[] = $u;
  }
}

$backup = $path . '.bak_' . date('Ymd_His');
copy($path, $backup);

$fixed = ['users' => $out];
file_put_contents($path, json_encode($fixed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "OK. Fixed users.json\n";
echo "Backup: $backup\n";
echo "Users: " . count($out) . "\n";
