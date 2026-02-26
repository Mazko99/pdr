<?php
declare(strict_types=1);

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../../src/chat_store.php';

if (session_status() !== PHP_SESSION_ACTIVE) @session_start();

header('Content-Type: application/json; charset=utf-8');

$action = (string)($_GET['action'] ?? '');

if ($action === 'send') {
  $uid = trim((string)($_POST['uid'] ?? ''));
  $text = trim((string)($_POST['text'] ?? ''));
  if ($uid === '' || $text === '') {
    echo json_encode(['ok' => false, 'error' => 'bad_request'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $res = chat_message_add($uid, 'admin', $text);
  // коли адмін відповів — для адміна непрочитане логічно = 0
  chat_mark_read_for($uid, 'admin');

  echo json_encode($res, JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === 'thread') {
  $uid = trim((string)($_GET['uid'] ?? ''));
  if ($uid === '') {
    echo json_encode(['ok' => false, 'error' => 'bad_request'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $t = chat_thread_get($uid);
  // відкритий чат = прочитано адміном
  chat_mark_read_for($uid, 'admin');

  echo json_encode(['ok' => true, 'thread' => $t], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === 'unread_total') {
  echo json_encode(['ok' => true, 'total' => chat_admin_unread_total()], JSON_UNESCAPED_UNICODE);
  exit;
}

echo json_encode(['ok' => false, 'error' => 'unknown_action'], JSON_UNESCAPED_UNICODE);