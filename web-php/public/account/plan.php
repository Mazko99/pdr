<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/users_store.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  redirect('/account/index.php');
}

csrf_verify($_POST['csrf'] ?? null);

$uid = auth_user_id();
if (!$uid) redirect('/login');

$plan = strtolower(trim((string)($_POST['plan'] ?? '')));

// ✅ узгоджуємо з mono_webhook.php: base | 12d
$allowed = ['base', '12d'];
if (!in_array($plan, $allowed, true)) {
  http_response_code(400);
  echo "Некоректний plan.";
  exit;
}

$user = user_find_by_id((string)$uid);
if (!$user) {
  auth_logout();
  redirect('/login');
}

$user['plan'] = $plan;
$user['paid_at'] = $user['paid_at'] ?? gmdate('c');

$days = ($plan === '12d') ? 12 : 30;
$user['expires_at'] = gmdate('c', time() + $days * 86400);

user_upsert($user);

// ✅ сесію не “відкриваємо в лоб”, а по факту expires_at
$_SESSION['has_access'] = true;

redirect('/account/index.php?tab=dashboard');