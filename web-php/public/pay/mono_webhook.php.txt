<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/users_store.php';
require_once __DIR__ . '/../../src/mono.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
  @session_start();
}

// === правильний storage: web-php/storage ===
$logDir = dirname(__DIR__, 3) . '/storage';
if (!is_dir($logDir)) {
  @mkdir($logDir, 0775, true);
}
$logFile = $logDir . '/mono_webhook.log';

function log_line(string $file, string $line): void {
  if (!@file_put_contents($file, "[" . date('c') . "] " . $line . "\n", FILE_APPEND)) {
    error_log("mono_webhook: cannot write log file: " . $file);
    error_log("mono_webhook: " . $line);
  }
}

// === GET тест (браузер) ===
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
  log_line($logFile, "GET ping from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
  http_response_code(200);
  echo 'OK (GET)';
  exit;
}

// Тільки POST для mono
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  log_line($logFile, "Method not allowed: " . ($_SERVER['REQUEST_METHOD'] ?? 'unknown'));
  http_response_code(405);
  echo 'method not allowed';
  exit;
}

$raw = (string)file_get_contents('php://input');
log_line($logFile, "RAW: " . $raw);

// зберемо хедери
$headers = [];
foreach ($_SERVER as $k => $v) {
  if (str_starts_with($k, 'HTTP_')) {
    $name = str_replace('_', '-', strtolower(substr($k, 5)));
    $headers[$name] = $v;
  }
}

$data = json_decode($raw, true);
if (!is_array($data)) {
  log_line($logFile, "BAD JSON");
  http_response_code(400);
  echo 'bad json';
  exit;
}

// (Опційно) верифікація підпису (рекомендовано)
// if (!mono_verify_webhook($raw, $headers)) {
//   log_line($logFile, "BAD SIGNATURE");
//   http_response_code(403);
//   echo 'bad signature';
//   exit;
// }

$status    = (string)($data['status'] ?? '');
$invoiceId = (string)($data['invoiceId'] ?? '');
$amount    = (int)($data['amount'] ?? 0);
$cardToken = (string)($data['cardToken'] ?? '');

// reference/orderId можуть приходити по-різному
$ref = (string)($data['reference'] ?? '');
if ($ref === '') $ref = (string)($data['orderId'] ?? '');

if ($ref === '' && isset($data['merchantPaymInfo']) && is_array($data['merchantPaymInfo'])) {
  $ref = (string)($data['merchantPaymInfo']['reference'] ?? '');
  if ($ref === '') $ref = (string)($data['merchantPaymInfo']['orderId'] ?? '');
}

log_line($logFile, "status={$status} invoiceId={$invoiceId} amount={$amount} ref={$ref}");

$userId = '';
$mode = '';
$plan = '';

if (str_starts_with($ref, 'buy_')) {
  // buy_{plan}_{userId}_{ts}
  $mode = 'buy';
  $parts = explode('_', $ref, 4);
  $plan = (string)($parts[1] ?? '');
  $userId = (string)($parts[2] ?? '');
} elseif (str_starts_with($ref, 'trial_bind_')) {
  // trial_bind_{userId}_{ts}
  $mode = 'trial';
  $parts = explode('_', $ref, 4);
  $userId = (string)($parts[2] ?? '');
} elseif (str_starts_with($ref, 'trial_charge_')) {
  // trial_charge_{plan}_{userId}_{ts}
  $mode = 'trial_charge';
  $parts = explode('_', $ref, 5);
  $plan = (string)($parts[2] ?? '');
  $userId = (string)($parts[3] ?? '');
}

if ($userId === '') {
  log_line($logFile, "NO USERID parsed from ref: " . $ref);
  http_response_code(200);
  echo 'ok';
  exit;
}

$u = user_find_by_id($userId);
if (!is_array($u)) {
  log_line($logFile, "USER NOT FOUND id=" . $userId);
  http_response_code(200);
  echo 'ok';
  exit;
}

// Успішні статуси (у різних флоу можуть відрізнятись)
$okStatuses = ['success', 'processed', 'ok'];
if (!in_array($status, $okStatuses, true)) {
  log_line($logFile, "NOT OK STATUS: " . $status);
  http_response_code(200);
  echo 'ok';
  exit;
}

// BUY: видати доступ одразу
if ($mode === 'buy') {
  $chosen = $plan !== '' ? $plan : (string)($u['buy_pending_plan'] ?? 'base');

  if ($chosen === '12d') {
    $u['plan'] = '12d';
    $u['expires_at'] = gmdate('c', time() + 12 * 86400);
  } else {
    $u['plan'] = 'base';
    $u['expires_at'] = gmdate('c', time() + 30 * 86400);
  }

  $u['mono_last_payment_at'] = gmdate('c');
  $u['buy_pending_invoice'] = null;
  $u['buy_pending_plan'] = null;

  user_upsert($u);

  log_line($logFile, "BUY OK user={$userId} plan={$u['plan']} expires_at={$u['expires_at']}");
  http_response_code(200);
  echo 'ok';
  exit;
}

// TRIAL: привʼязали карту → відкриваємо trial + зберігаємо cardToken
if ($mode === 'trial') {
  $pendingPlan = (string)($u['trial_pending_plan'] ?? 'base');

  $u['trial_used'] = true;
  $u['trial_started_at'] = (string)($u['trial_started_at'] ?? gmdate('c'));
  $u['trial_expires_at'] = gmdate('c', time() + 3 * 86400);
  $u['trial_cancelled'] = false;

  // доступ на 3 дні
  $u['plan'] = $pendingPlan;
  $u['expires_at'] = (string)$u['trial_expires_at'];

  if ($cardToken !== '') {
    $u['mono_card_token'] = $cardToken;
  }

  $u['trial_pending_invoice'] = null;

  user_upsert($u);

  log_line($logFile, "TRIAL OK user={$userId} plan={$u['plan']} trial_expires_at={$u['trial_expires_at']}");
  http_response_code(200);
  echo 'ok';
  exit;
}

// trial_charge — просто ack
log_line($logFile, "TRIAL_CHARGE ACK user={$userId} plan={$plan}");
http_response_code(200);
echo 'ok';
exit;