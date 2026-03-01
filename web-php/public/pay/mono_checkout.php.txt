<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/users_store.php';
require_once __DIR__ . '/../../src/mono.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
  @session_start();
}

function json_out(array $a): void {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  exit;
}

$userId = (string)($_SESSION['user_id'] ?? '');
$isAuth = ($userId !== '');

$mode = (string)($_GET['mode'] ?? 'buy');   // buy|trial
$plan = (string)($_GET['plan'] ?? 'base');  // base|12d

// ціни в "копійках"
$PRICE = [
  'base' => 69900,  // 699.00 грн
  '12d'  => 38999,  // 389.99 грн
];

if (!isset($PRICE[$plan])) {
  http_response_code(400);
  exit('Bad plan');
}

$app = mono_app_url();
if ($app === '') {
  http_response_code(500);
  exit('APP_URL missing');
}

$successUrl = $app . '/account?tab=dashboard&pay=success';
$failUrl    = $app . '/account?tab=dashboard&pay=fail';

// ✅ ВАЖЛИВО: вебхук тепер через /pay/?a=webhook
$webhookUrl = $app . '/pay/?a=webhook';

// Гість: робимо тимчасовий userId як g_{sessionId}
if (!$isAuth) {
  if (!isset($_SESSION['guest_chat_id'])) {
    $_SESSION['guest_chat_id'] = 'g_' . bin2hex(random_bytes(10));
  }
  $userId = (string)$_SESSION['guest_chat_id'];
}

$u = user_find_by_id($userId);
if (!is_array($u)) {
  $u = [
    'id' => $userId,
    'email' => '',
    'name' => '',
    'plan' => 'free',
    'created_at' => gmdate('c'),
  ];
  user_upsert($u);
}

$trialUsed = (bool)($u['trial_used'] ?? false);

if ($mode === 'trial') {
  if ($trialUsed) {
    header('Location: ' . $failUrl . '&reason=trial_used', true, 302);
    exit;
  }

  // сума для “привʼязки”
  $holdAmount = (int)mono_env('MONO_TRIAL_HOLD_AMOUNT', '100');
  if ($holdAmount < 1) $holdAmount = 100;

  $payload = [
    'amount' => $holdAmount,
    'ccy'    => mono_ccy(),
    'merchantPaymInfo' => [
      'reference' => 'trial_bind_' . $userId . '_' . time(),
      'destination' => 'ProstoPDR: привʼязка картки для trial',
      'comment' => 'Trial bind',
    ],
    'redirectUrl' => $successUrl,
    'webHookUrl'  => $webhookUrl,
    'saveCardData' => [
      'saveCard' => true
    ],
  ];

  $r = mono_http('POST', '/api/merchant/invoice/create', $payload);
  if ($r['code'] !== 200) {
    header('Location: ' . $failUrl . '&reason=mono_' . $r['code'], true, 302);
    exit;
  }

  $pageUrl = (string)($r['data']['pageUrl'] ?? '');
  $invoiceId = (string)($r['data']['invoiceId'] ?? '');
  if ($pageUrl === '' || $invoiceId === '') {
    header('Location: ' . $failUrl . '&reason=mono_bad_response', true, 302);
    exit;
  }

  $u['trial_pending_invoice'] = $invoiceId;
  $u['trial_pending_plan'] = $plan;
  $u['trial_started_at'] = gmdate('c');
  $u['trial_cancelled'] = false;
  user_upsert($u);

  header('Location: ' . $pageUrl, true, 302);
  exit;
}

// BUY
$amount = (int)$PRICE[$plan];

$payload = [
  'amount' => $amount,
  'ccy'    => mono_ccy(),
  'merchantPaymInfo' => [
    'reference' => 'buy_' . $plan . '_' . $userId . '_' . time(),
    'destination' => 'ProstoPDR: покупка плану ' . $plan,
    'comment' => 'Buy ' . $plan,
  ],
  'redirectUrl' => $successUrl,
  'webHookUrl'  => $webhookUrl,
];

$r = mono_http('POST', '/api/merchant/invoice/create', $payload);
if ($r['code'] !== 200) {
  header('Location: ' . $failUrl . '&reason=mono_' . $r['code'], true, 302);
  exit;
}

$pageUrl = (string)($r['data']['pageUrl'] ?? '');
$invoiceId = (string)($r['data']['invoiceId'] ?? '');
if ($pageUrl === '' || $invoiceId === '') {
  header('Location: ' . $failUrl . '&reason=mono_bad_response', true, 302);
  exit;
}

$u['buy_pending_invoice'] = $invoiceId;
$u['buy_pending_plan'] = $plan;
user_upsert($u);

header('Location: ' . $pageUrl, true, 302);
exit;