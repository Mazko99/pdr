<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/users_store.php';
require_once __DIR__ . '/../../src/mono.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
  @session_start();
}

$raw = (string)file_get_contents('php://input');

// === LOGS (щоб точно бачити що прилітає з mono) ===
$logDir = dirname(__DIR__, 2) . '/storage';
if (!is_dir($logDir)) {
  @mkdir($logDir, 0775, true);
}
@file_put_contents($logDir . '/mono_webhook.log', "[" . date('c') . "] RAW: " . $raw . "\n", FILE_APPEND);

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
  http_response_code(400);
  echo 'bad json';
  exit;
}

// (Опційно) верифікація підпису (рекомендовано)
// if (!mono_verify_webhook($raw, $headers)) {
//   http_response_code(403);
//   echo 'bad signature';
//   exit;
// }

$status    = (string)($data['status'] ?? '');
$invoiceId = (string)($data['invoiceId'] ?? '');
$amount    = (int)($data['amount'] ?? 0);

// cardToken може бути в залежності від режиму (токенізація)
$cardToken = (string)($data['cardToken'] ?? '');

// === ВАЖЛИВО: reference може бути пустий. Тоді беремо orderId. ===
$ref = (string)($data['reference'] ?? '');
if ($ref === '') $ref = (string)($data['orderId'] ?? '');

if ($ref === '' && isset($data['merchantPaymInfo']) && is_array($data['merchantPaymInfo'])) {
  $ref = (string)($data['merchantPaymInfo']['reference'] ?? '');
  if ($ref === '') $ref = (string)($data['merchantPaymInfo']['orderId'] ?? '');
}

// залогуємо ref/status для дебагу
@file_put_contents(
  $logDir . '/mono_webhook.log',
  "[" . date('c') . "] status={$status} invoiceId={$invoiceId} amount={$amount} ref={$ref}\n",
  FILE_APPEND
);

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
  $plan = '';
} elseif (str_starts_with($ref, 'trial_charge_')) {
  // trial_charge_{plan}_{userId}_{ts}
  // Це списання після trial — можна логати/оновлювати, але доступ уже видається кроном
  $mode = 'trial_charge';
  $parts = explode('_', $ref, 5);
  $plan = (string)($parts[2] ?? '');
  $userId = (string)($parts[3] ?? '');
}

if ($userId === '') {
  http_response_code(200);
  echo 'ok';
  exit;
}

$u = user_find_by_id($userId);
if (!is_array($u)) {
  http_response_code(200);
  echo 'ok';
  exit;
}

// === Обробляємо тільки успішні статуси ===
// інколи mono може віддати processed/ok залежно від флоу
$okStatuses = ['success', 'processed', 'ok'];
if (!in_array($status, $okStatuses, true)) {
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

  // тимчасовий доступ на 3 дні
  $u['plan'] = $pendingPlan;
  $u['expires_at'] = (string)$u['trial_expires_at'];

  if ($cardToken !== '') {
    $u['mono_card_token'] = $cardToken;
  }

  $u['trial_pending_invoice'] = null;

  user_upsert($u);

  http_response_code(200);
  echo 'ok';
  exit;
}

// trial_charge — просто ack
http_response_code(200);
echo 'ok';
exit;