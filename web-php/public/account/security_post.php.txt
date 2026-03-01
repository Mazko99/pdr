<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/users_store.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

function flash_err(string $msg): void {
  $_SESSION['flash_err'] = $msg;
}
function flash_ok(string $msg): void {
  $_SESSION['flash_ok'] = $msg;
}
function back_security(): void {
  header('Location: /account?tab=security', true, 302);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method not allowed');
}

csrf_verify($_POST['csrf'] ?? null);

$uid = $_SESSION['user_id'] ?? null;
if (!$uid) {
  header('Location: /login', true, 302);
  exit;
}
$uidStr = (string)$uid;

$action = (string)($_POST['action'] ?? '');

$user = user_find_by_id($uidStr);
if (!$user) {
  flash_err('Користувача не знайдено.');
  back_security();
}

if ($action === 'revoke_all') {
  $keepCurrent = (string)($_POST['keep_current'] ?? '') === '1';
  $except = $keepCurrent ? (session_id() ?: null) : null;

  if (function_exists('sessions_revoke_all_for_user')) {
    sessions_revoke_all_for_user($uidStr, $except);
    flash_ok($keepCurrent ? 'Вийшли з усіх інших пристроїв.' : 'Вийшли з усіх пристроїв (включно з цим).');
  } else {
    flash_err('Функції керування сеансами не підключені (sessions_revoke_all_for_user).');
  }

  // якщо не залишаємо поточний — вийти
  if (!$keepCurrent) {
    $_SESSION = [];
    @session_destroy();
    header('Location: /login?ok=' . rawurlencode('Сеанси скинуто. Увійдіть знову.'), true, 302);
    exit;
  }

  back_security();
}

if ($action === 'revoke_one') {
  $sid = (string)($_POST['sid'] ?? '');
  if ($sid === '') {
    flash_err('Не вказано SID.');
    back_security();
  }

  // не даємо прибити поточний тут (щоб не було “вилетів”)
  if ($sid === (string)session_id()) {
    flash_err('Це поточний сеанс. Для виходу натисніть "Вийти" або "Вийти з усіх пристроїв".');
    back_security();
  }

  if (function_exists('session_revoke_for_user')) {
    session_revoke_for_user($uidStr, $sid);
    flash_ok('Сеанс завершено.');
  } else {
    flash_err('Функції керування сеансами не підключені (session_revoke_for_user).');
  }

  back_security();
}

if ($action === 'change_password') {
  $cur = (string)($_POST['current_password'] ?? '');
  $new = (string)($_POST['new_password'] ?? '');
  $new2 = (string)($_POST['new_password_confirm'] ?? '');
  $revokeOthers = (string)($_POST['revoke_others'] ?? '') === '1';

  if ($cur === '' || $new === '' || $new2 === '') {
    flash_err('Заповніть всі поля пароля.');
    back_security();
  }
  if (strlen($new) < 8) {
    flash_err('Новий пароль має бути мінімум 8 символів.');
    back_security();
  }
  if ($new !== $new2) {
    flash_err('Нові паролі не співпадають.');
    back_security();
  }

  if (!function_exists('user_verify_password') || !user_verify_password($user, $cur)) {
    flash_err('Поточний пароль невірний.');
    back_security();
  }

  $hash = password_hash($new, PASSWORD_DEFAULT);
  user_update($uidStr, ['password_hash' => $hash]);

  if ($revokeOthers && function_exists('sessions_revoke_all_for_user')) {
    $except = session_id() ?: null;
    sessions_revoke_all_for_user($uidStr, $except);
  }

  flash_ok($revokeOthers
    ? 'Пароль змінено. Вихід з інших пристроїв виконано.'
    : 'Пароль змінено.'
  );

  back_security();
}

flash_err('Невідома дія.');
back_security();