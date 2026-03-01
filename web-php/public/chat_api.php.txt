<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/chat_store.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
  @session_start();
}

header('Content-Type: application/json; charset=utf-8');

function j(array $a): void {
  echo json_encode($a, JSON_UNESCAPED_UNICODE);
  exit;
}

function chat_csrf_token(): string {
  if (empty($_SESSION['chat_csrf']) || !is_string($_SESSION['chat_csrf'])) {
    $_SESSION['chat_csrf'] = bin2hex(random_bytes(16));
  }
  return (string)$_SESSION['chat_csrf'];
}

function chat_csrf_verify(?string $token): void {
  $sess = (string)($_SESSION['chat_csrf'] ?? '');
  $tok  = (string)$token;
  if ($sess === '' || $tok === '' || !hash_equals($sess, $tok)) {
    j(['ok' => false, 'error' => 'csrf']);
  }
}

function chat_get_thread_id(): string {
  // якщо авторизований — thread = user_id
  if (function_exists('auth_user_id')) {
    $uid = (string)(auth_user_id() ?? '');
    if ($uid !== '') return $uid;
  }

  // гість — тримаємо стабільний g_... в сесії
  if (empty($_SESSION['chat_guest_id']) || !is_string($_SESSION['chat_guest_id'])) {
    $_SESSION['chat_guest_id'] = 'g_' . bin2hex(random_bytes(8));
  }
  return (string)$_SESSION['chat_guest_id'];
}

$action = (string)($_GET['action'] ?? '');

if ($action === 'init') {
  j([
    'ok' => true,
    'csrf' => chat_csrf_token(),
    'thread_id' => chat_get_thread_id(),
    'is_auth' => (function_exists('auth_user_id') && (string)(auth_user_id() ?? '') !== ''),
  ]);
}

if ($action === 'fetch') {
  $threadId = chat_get_thread_id();
  $t = chat_thread_get($threadId);

  // користувач відкрив чат = прочитано користувачем
  chat_mark_read_for($threadId, 'user');

  j(['ok' => true, 'thread' => $t]);
}

if ($action === 'send') {
  chat_csrf_verify($_POST['csrf'] ?? null);

  $threadId = chat_get_thread_id();
  $text = trim((string)($_POST['text'] ?? ''));

  // meta (для гостя)
  $name  = trim((string)($_POST['name'] ?? ''));
  $email = trim((string)($_POST['email'] ?? ''));

  if ($text === '') {
    j(['ok' => false, 'error' => 'empty']);
  }

  // легка валідація email, але не блокуємо повністю якщо не валідний
  if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $email = '';
  }

  $meta = [];
  if ($name !== '') $meta['name'] = $name;
  if ($email !== '') $meta['email'] = $email;

  $res = chat_message_add($threadId, 'user', $text, $meta);
  j($res);
}

j(['ok' => false, 'error' => 'unknown_action']);