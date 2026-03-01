<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/chat_store.php';
require_once __DIR__ . '/../../../src/users_store.php';

if (session_status() !== PHP_SESSION_ACTIVE) @session_start();

$uid = auth_user_id();
if (!$uid) {
  http_response_code(401);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => false, 'error' => 'not_auth'], JSON_UNESCAPED_UNICODE);
  exit;
}

session_enforce_not_revoked((string)$uid);
session_register_current((string)$uid);

$after = (int)($_GET['after'] ?? 0);
$messages = chat_messages_since((string)$uid, $after);

// якщо юзер відкрив чат — вважаємо прочитаним для юзера
if (!empty($_GET['mark_read'])) {
  chat_mark_read_for((string)$uid, 'user');
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'messages' => $messages], JSON_UNESCAPED_UNICODE);