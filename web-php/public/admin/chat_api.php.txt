<?php
declare(strict_types=1);

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../../src/chat_store.php';

if (session_status() !== PHP_SESSION_ACTIVE) @session_start();

header('Content-Type: application/json; charset=utf-8');

function j(array $a): void {
  echo json_encode($a, JSON_UNESCAPED_UNICODE);
  exit;
}

$action = (string)($_GET['action'] ?? '');

if ($action === 'send') {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') j(['ok'=>false,'error'=>'method']);

  $uid = trim((string)($_POST['uid'] ?? ''));
  $text = trim((string)($_POST['text'] ?? ''));

  if ($uid === '' || $text === '') j(['ok'=>false,'error'=>'bad_request']);

  $res = chat_message_add($uid, 'admin', $text);
  chat_mark_read_for($uid, 'admin');

  j($res);
}

if ($action === 'thread') {
  $uid = trim((string)($_GET['uid'] ?? ''));
  if ($uid === '') j(['ok'=>false,'error'=>'bad_request']);

  $t = chat_thread_get($uid);
  chat_mark_read_for($uid, 'admin');

  j(['ok'=>true,'thread'=>$t]);
}

if ($action === 'unread_total') {
  j(['ok'=>true,'total'=>chat_admin_unread_total()]);
}

j(['ok'=>false,'error'=>'unknown_action']);