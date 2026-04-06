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

// ✅ єдині коди тарифів у всьому проекті
$allowed = ['basic', 'mini12'];
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

$days = ($plan === 'mini12') ? 12 : 30;
$nowIso = gmdate('c');

$user['plan'] = $plan;
$user['paid_at'] = $nowIso;
$user['plan_set_at'] = $nowIso;
$user['mono_last_payment_at'] = $nowIso;
$user['buy_pending_plan'] = null;
$user['buy_pending_invoice'] = null;
$user['expires_at'] = gmdate('c', time() + $days * 86400);

user_upsert($user);

// ✅ сесію не “відкриваємо в лоб”, а по факту expires_at
$_SESSION['has_access'] = true;

redirect('/account/index.php?tab=dashboard');