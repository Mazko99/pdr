<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/users_store.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method not allowed');
}

csrf_verify($_POST['csrf'] ?? null);

$mode = (string)($_POST['mode'] ?? 'login');
$email = trim((string)($_POST['email'] ?? ''));
$pass  = (string)($_POST['password'] ?? '');
$name  = trim((string)($_POST['name'] ?? ''));
$pass2 = (string)($_POST['password_confirm'] ?? '');

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  redirect('/login?err=' . rawurlencode('Вкажи коректний email'));
}

if ($mode === 'register') {
  if (strlen($pass) < 8) {
    redirect('/login?tab=register&err=' . rawurlencode('Пароль має бути мінімум 8 символів'));
  }
  if ($pass !== $pass2) {
    redirect('/login?tab=register&err=' . rawurlencode('Паролі не співпадають'));
  }

  $existing = user_find_by_email($email);
  if ($existing) {
    redirect('/login?tab=register&err=' . rawurlencode('Цей email вже зареєстрований. Спробуй увійти.'));
  }

  $hash = password_hash($pass, PASSWORD_DEFAULT);
  $uid = (string)user_create($email, $name, $hash);

  auth_login($uid);

  // sessions.json (опційно)
  if (function_exists('session_register_current')) {
    session_register_current($uid, 'Email register');
  }

  // device policy: 2 remembered + 1 active
  if (function_exists('ds_on_login')) {
    $res = ds_on_login($uid, session_id(), 2);
    if (!($res['ok'] ?? false)) {
      auth_logout();
      redirect('/login?reason=max_devices');
    }
  }

  auth_refresh_access();
  redirect('/account/index.php');
}

// ---- login ----
if ($pass === '') {
  redirect('/login?err=' . rawurlencode('Введи пароль'));
}

$user = user_find_by_email($email);
if (!$user) {
  redirect('/login?err=' . rawurlencode('Акаунт з таким email не знайдено. Спочатку зареєструйся.'));
}

if (!user_verify_password($user, $pass)) {
  redirect('/login?err=' . rawurlencode('Невірний пароль'));
}

$uid = (string)($user['id'] ?? '');
if ($uid === '') {
  redirect('/login?err=' . rawurlencode('Помилка акаунта: відсутній ID'));
}

auth_login($uid);

// sessions.json (опційно)
if (function_exists('session_register_current')) {
  session_register_current($uid, 'Email login');
}

// device policy: 2 remembered + 1 active
if (function_exists('ds_on_login')) {
  $res = ds_on_login($uid, session_id(), 2);
  if (!($res['ok'] ?? false)) {
    auth_logout();
    redirect('/login?reason=max_devices');
  }
}

auth_refresh_access();
redirect('/account/index.php');