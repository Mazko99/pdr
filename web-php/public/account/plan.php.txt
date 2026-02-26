<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/users_store.php'; // ✅ FIX: гарантовано підключає функції user_find_by_id/user_update...

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

// Тільки POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  redirect('/account/index.php');
}

// ✅ CSRF (твоя функція САМА робить exit якщо токен невалидний)
csrf_verify($_POST['csrf'] ?? null);

// uid
$uid = auth_user_id();
if (!$uid) redirect('/login');

// plan
$plan = strtolower(trim((string)($_POST['plan'] ?? '')));

/**
 * ✅ ДОДАНО 2-й тариф:
 * - basic  -> 30 днів (як було)
 * - mini12 -> 12 днів (349 грн)
 */
$allowed = ['basic', 'mini12'];
if (!in_array($plan, $allowed, true)) {
  http_response_code(400);
  echo "Некоректний plan.";
  exit;
}

// user
$user = user_find_by_id($uid);
if (!$user) {
  auth_logout();
  redirect('/login');
}

// ✅ ставимо тариф
$user['plan'] = $plan;

// ✅ Дати старт підписки (якщо ще не було)
$user['paid_at'] = $user['paid_at'] ?? gmdate('c');

/**
 * ✅ Тривалість:
 * basic  -> 30 днів
 * mini12 -> 12 днів
 */
$days = 30;
if ($plan === 'mini12') {
  $days = 12;
}

// ✅ встановлюємо/оновлюємо дату завершення
$user['expires_at'] = gmdate('c', time() + $days * 86400);

// ✅ зберігаємо
if (function_exists('user_update')) {
  user_update($uid, $user);
} elseif (function_exists('user_save')) {
  user_save($user);
} elseif (function_exists('users_store_update')) {
  users_store_update($uid, $user);
} elseif (function_exists('user_write')) {
  user_write($uid, $user);
} else {
  http_response_code(500);
  echo "Не знайдена функція оновлення користувача у users_store.php (user_update/user_save/users_store_update/user_write).";
  exit;
}

/**
 * ✅ FIX: не ставимо has_access=true “в лоб”.
 * Краще порахувати доступ із user (plan + expires).
 * Якщо у тебе ще нема user_has_access() — тоді просто true для дозволених планів.
 */
if (function_exists('user_has_access')) {
  $_SESSION['has_access'] = user_has_access($user);
} else {
  $_SESSION['has_access'] = in_array($plan, $allowed, true);
}

// назад в кабінет (краще конкретний файл, щоб не ловити rewrite-loop)
redirect('/account/index.php?tab=dashboard');