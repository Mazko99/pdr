<?php
declare(strict_types=1);

function users_storage_path(): string {
  return dirname(__DIR__) . '/storage/users.json';
}

function users_storage_init(): void {
  $path = users_storage_path();
  $dir = dirname($path);

  if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
  }

  if (!is_file($path)) {
    file_put_contents($path, json_encode(['users' => []], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }
}

function users_read_all(): array {
  users_storage_init();
  $raw = file_get_contents(users_storage_path());
  $data = json_decode((string)$raw, true);

  if (!is_array($data) || !isset($data['users']) || !is_array($data['users'])) {
    $data = ['users' => []];
  }
  return $data;
}

function users_write_all(array $data): void {
  users_storage_init();
  $path = users_storage_path();

  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  if ($json === false) {
    throw new RuntimeException('Не вдалося зберегти users.json');
  }

  // атомарний запис із lock
  $fp = fopen($path, 'c+');
  if (!$fp) throw new RuntimeException('Не вдалося відкрити users.json');

  flock($fp, LOCK_EX);
  ftruncate($fp, 0);
  fwrite($fp, $json);
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);
}

function user_find_by_email(string $email): ?array {
  $email = strtolower(trim($email));
  $data = users_read_all();

  foreach ($data['users'] as $u) {
    if (isset($u['email']) && strtolower((string)$u['email']) === $email) {
      return $u;
    }
  }
  return null;
}

function user_find_by_id(string $id): ?array {
  $data = users_read_all();
  foreach ($data['users'] as $u) {
    if ((string)($u['id'] ?? '') === $id) {
      return $u;
    }
  }
  return null;
}

function user_create(string $email, string $name, string $passwordHash): string {
  $data = users_read_all();

  $id = bin2hex(random_bytes(12));
  $data['users'][] = [
    'id' => $id,
    'email' => strtolower(trim($email)),
    'name' => trim($name),
    'password_hash' => $passwordHash,
    'plan' => null,               // basic | personal | null
    'plan_set_at' => null,
    'created_at' => date('c'),
  ];

  users_write_all($data);
  return $id;
}

function user_verify_password(array $user, string $password): bool {
  $hash = (string)($user['password_hash'] ?? '');
  if ($hash === '') return false;
  return password_verify($password, $hash);
}

function user_update_plan(string $userId, string $plan): void {
  $allowed = ['basic', 'personal'];
  if (!in_array($plan, $allowed, true)) {
    throw new InvalidArgumentException('Invalid plan');
  }

  $data = users_read_all();
  foreach ($data['users'] as &$u) {
    if ((string)($u['id'] ?? '') === $userId) {
      $u['plan'] = $plan;
      $u['plan_set_at'] = date('c');
      break;
    }
  }
  unset($u);

  users_write_all($data);
}
