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

$text = trim((string)($_POST['text'] ?? ''));
if ($text === '') {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => false, 'error' => 'empty'], JSON_UNESCAPED_UNICODE);
  exit;
}

$res = chat_message_add((string)$uid, 'user', $text);

header('Content-Type: application/json; charset=utf-8');
echo json_encode($res, JSON_UNESCAPED_UNICODE);