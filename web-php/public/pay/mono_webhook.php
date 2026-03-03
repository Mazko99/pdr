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
  $msg = "[" . date('c') . "] " . $line;
  error_log("MONO_WEBHOOK " . $msg);
  @file_put_contents($file, $msg . "\n", FILE_APPEND);
}

function norm_plan(string $p): string {
  $p = trim(strtolower($p));
  $map = [
    '30' => 'base',
    '30d' => 'base',
    'base' => 'base',

    '12' => '12d',
    '12d' => '12d',
    'personal' => '12d',   // якщо твій 12-денний план називається personal у checkout
    'trial12' => '12d',
  ];
  return $map[$p] ?? $p;
}

function extract_user_id_from_ref(string $ref): string {
  // шукаємо найбільш схоже на id користувача (цифри або uuid-подібне)
  // 1) якщо у тебе id цифрами
  if (preg_match('~(?:^|_)(\d{1,20})(?:_|$)~', $ref, $m)) {
    return (string)$m[1];
  }
  // 2) якщо колись зробиш uuid — витягне теж
  if (preg_match('~(?:^|_)([a-f0-9]{8,})(?:_|$)~i', $ref, $m2)) {
    return (string)$m2[1];
  }
  return '';
}

// === GET тест (браузер) ===
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
  log_line($logFile, "GET ping uri=" . ($_SERVER['REQUEST_URI'] ?? '') . " ip=" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
  http_response_code(200);
  echo 'OK (GET)';
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  log_line($logFile, "Method not allowed: " . ($_SERVER['REQUEST_METHOD'] ?? 'unknown'));
  http_response_code(405);
  echo 'method not allowed';
  exit;
}

$raw = (string)file_get_contents('php://input');
log_line($logFile, "RAW=" . $raw);

// headers
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

// (опційно) signature verify
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

// reference/orderId fallback
$ref = (string)($data['reference'] ?? '');
if ($ref === '') $ref = (string)($data['orderId'] ?? '');
if ($ref === '' && isset($data['merchantPaymInfo']) && is_array($data['merchantPaymInfo'])) {
  $ref = (string)($data['merchantPaymInfo']['reference'] ?? '');
  if ($ref === '') $ref = (string)($data['merchantPaymInfo']['orderId'] ?? '');
}

log_line($logFile, "PARSED status={$status} invoiceId={$invoiceId} amount={$amount} ref={$ref}");

$userId = '';
$mode = '';
$plan = '';

// --- РОЗШИРЕНИЙ ПАРСИНГ reference ---
// підтримуємо твої формати + запасні
// 1) buy_{plan}_{userId}_{ts}
if (str_starts_with($ref, 'buy_')) {
  $mode = 'buy';
  $parts = explode('_', $ref);
  $plan = (string)($parts[1] ?? '');
  $userId = (string)($parts[2] ?? '');
}

// 2) trial_bind_{userId}_{ts}
elseif (str_starts_with($ref, 'trial_bind_')) {
  $mode = 'trial';
  $parts = explode('_', $ref);
  $userId = (string)($parts[2] ?? '');
}

// 3) trial_charge_{plan}_{userId}_{ts}
elseif (str_starts_with($ref, 'trial_charge_')) {
  $mode = 'trial_charge';
  $parts = explode('_', $ref);
  $plan = (string)($parts[2] ?? '');
  $userId = (string)($parts[3] ?? '');
}

// 4) fallback: якщо ref типу trial_{plan}_{userId}_{ts} або checkout_{...}
elseif (str_contains($ref, 'trial')) {
  // пробуємо витягнути userId з рядка
  $mode = 'trial';
  $userId = extract_user_id_from_ref($ref);
  // пробуємо витягнути план як base/personal/12/30 якщо він є в ref
  if (preg_match('~(?:^|_)(base|personal|12d|12|30|30d)(?:_|$)~i', $ref, $m)) {
    $plan = (string)$m[1];
  }
}
elseif (str_contains($ref, 'checkout') || str_contains($ref, 'order')) {
  // краще хоч так, ніж нуль
  $userId = extract_user_id_from_ref($ref);
  if ($userId !== '') $mode = 'buy'; // або trial — якщо в ref є trial, але вище вже зловили
}

// нормалізуємо plan
$plan = norm_plan($plan);

log_line($logFile, "MODE={$mode} userId={$userId} plan={$plan}");

if ($userId === '') {
  log_line($logFile, "EXIT: NO USERID");
  http_response_code(200);
  echo 'ok';
  exit;
}

$u = user_find_by_id($userId);
if (!is_array($u)) {
  log_line($logFile, "EXIT: USER NOT FOUND id={$userId}");
  http_response_code(200);
  echo 'ok';
  exit;
}

/**
 * ВАЖЛИВО:
 * Для прив'язки (trial_hold 1 грн) mono часто шле статус не тільки "success".
 * Тому для TRIAL дозволяємо: success/processed/ok/hold/processing
 * Для BUY краще лишити тільки success/processed/ok.
 */
$okBuy   = ['success', 'processed', 'ok'];
$okTrial = ['success', 'processed', 'ok', 'hold', 'processing'];

if ($mode === 'trial' || $mode === 'trial_charge') {
  if (!in_array($status, $okTrial, true)) {
    log_line($logFile, "EXIT: TRIAL status not ok ({$status})");
    http_response_code(200);
    echo 'ok';
    exit;
  }
} else {
  if (!in_array($status, $okBuy, true)) {
    log_line($logFile, "EXIT: BUY status not ok ({$status})");
    http_response_code(200);
    echo 'ok';
    exit;
  }
}

// BUY
if ($mode === 'buy') {
  $chosen = $plan !== '' ? $plan : (string)($u['buy_pending_plan'] ?? 'base');
  $chosen = norm_plan($chosen);

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

  log_line($logFile, "BUY OK -> plan={$u['plan']} expires_at={$u['expires_at']}");
  http_response_code(200);
  echo 'ok';
  exit;
}

// TRIAL (включно з fallback trial_...)
if ($mode === 'trial') {
  $pendingPlan = (string)($u['trial_pending_plan'] ?? 'base');
  $pendingPlan = norm_plan($pendingPlan);

  // якщо webhook приніс plan — використовуємо його
  if ($plan !== '') $pendingPlan = norm_plan($plan);

  $u['trial_used'] = true;
  $u['trial_started_at'] = (string)($u['trial_started_at'] ?? gmdate('c'));
  $u['trial_expires_at'] = gmdate('c', time() + 3 * 86400);
  $u['trial_cancelled'] = false;

  $u['plan'] = $pendingPlan;
  $u['expires_at'] = (string)$u['trial_expires_at'];

  if ($cardToken !== '') {
    $u['mono_card_token'] = $cardToken;
  }

  $u['trial_pending_invoice'] = null;

  user_upsert($u);

  log_line($logFile, "TRIAL OK -> plan={$u['plan']} trial_expires_at={$u['trial_expires_at']}");
  http_response_code(200);
  echo 'ok';
  exit;
}

// trial_charge — просто ack (поки не використовуємо)
log_line($logFile, "TRIAL_CHARGE ACK");
http_response_code(200);
echo 'ok';
exit;