<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/users_store.php';

if (session_status() !== PHP_SESSION_ACTIVE) @session_start();

$userId = (string)($_SESSION['user_id'] ?? '');
if ($userId === '') {
  header('Location: /login', true, 302);
  exit;
}

csrf_verify($_POST['csrf'] ?? null);

$u = user_find_by_id($userId);
if (!is_array($u)) {
  header('Location: /account?tab=dashboard', true, 302);
  exit;
}

// якщо це trial — ставимо cancelled і відрубаємо в кінці trial (або одразу)
$u['trial_cancelled'] = true;

// Можеш одразу відрубати доступ:
$u['plan'] = 'free';
$u['expires_at'] = null;

user_upsert($u);

header('Location: /account?tab=dashboard&cancel=1', true, 302);
exit;