<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/users_store.php';
require_once __DIR__ . '/../../src/mono_acquiring.php';
require_once __DIR__ . '/../../src/mono_payments_store.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
  @session_start();
}
// === DEBUG MODE (temporary) ===
$__debug = (isset($_GET['debug']) && $_GET['debug'] === '1');
function debug_dump($title, $data): void {
  header('Content-Type: text/plain; charset=utf-8');
  echo "=== {$title} ===\n";
  print_r($data);
  exit;
}

// ✅ ENDPOINT ONLY: POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  http_response_code(405);
  header('Content-Type: text/plain; charset=utf-8');
  echo "Method not allowed. Use POST.\n";
  exit;
}

// CSRF
csrf_verify($_POST['csrf'] ?? null);

// action: buy|trial
$action = (string)($_POST['action'] ?? 'buy');
if ($action !== 'buy' && $action !== 'trial') $action = 'buy';

// plan: 12|30
$plan = (string)($_POST['plan'] ?? '30');
if ($plan !== '12' && $plan !== '30') $plan = '30';

// auth
$uid = auth_user_id();
if ($uid === null || $uid === '') {
  redirect('/login?err=' . rawurlencode('Спочатку увійди в акаунт, щоб оформити оплату.'));
}

$u = user_find_by_id((string)$uid);
if (!is_array($u)) {
  redirect('/login?err=' . rawurlencode('Акаунт не знайдено.'));
}

// amounts in kop
$amount12  = (int)getenv('PLAN_12_AMOUNT');   // e.g. 38999
$amount30  = (int)getenv('PLAN_30_AMOUNT');   // e.g. 69900
$trialHold = (int)getenv('TRIAL_HOLD_AMOUNT'); // e.g. 100
$trialDays = (int)getenv('TRIAL_DAYS');
if ($trialDays <= 0) $trialDays = 3;

// fallback defaults
$amount = ($plan === '12') ? $amount12 : $amount30;
if ($amount <= 0) $amount = ($plan === '12') ? 38999 : 69900;
if ($trialHold <= 0) $trialHold = 100;

// URLs from ENV
$returnUrl  = mono_env('MONO_RETURN_URL', '');
$webhookUrl = mono_env('MONO_WEBHOOK_URL', '');

if ($returnUrl === '' || $webhookUrl === '') {
  redirect('/account/index.php?tab=dashboard&err=' . rawurlencode('Mono не налаштовано: MONO_RETURN_URL / MONO_WEBHOOK_URL'));
}

/**
 * ✅ IMPORTANT: unify plan codes across your project
 * - 30 days -> basic
 * - 12 days -> mini12
 * (because your account/plan.php expects these codes)
 */
$planKey = ($plan === '12') ? 'mini12' : 'basic';

// ✅ reference format (unique)
$ts = time();
if ($action === 'trial') {
  $orderRef = 'trial_bind_' . $uid . '_' . $ts;
} else {
  $orderRef = 'buy_' . $planKey . '_' . $uid . '_' . $ts;
}

$title = ($action === 'trial')
  ? ('Тріал ' . $trialDays . ' дні + прив’язка картки — ' . ($planKey === 'mini12' ? 'План 12 днів' : 'Базовий план'))
  : ('Оплата: ' . ($planKey === 'mini12' ? 'План 12 днів' : 'Базовий план'));

// trial -> hold amount, buy -> plan amount
$finalAmount = ($action === 'trial') ? $trialHold : $amount;

$payload = [
  'amount' => $finalAmount,
  'ccy' => 980,
  'merchantPaymInfo' => [
    'reference' => $orderRef,
    'destination' => $title,
    'comment' => $title,
    'basketOrder' => [
      [
        'name' => $title,
        'qty' => 1,
        'sum' => $finalAmount,
        'code' => $planKey,
        'icon' => '',
        'unit' => 'шт',
      ]
    ],
  ],
  'redirectUrl' => $returnUrl . (str_contains($returnUrl, '?') ? '&' : '?') . 'invoice=' . rawurlencode($orderRef),
  'webHookUrl'  => $webhookUrl,
];

// If trial — save card
if ($action === 'trial') {
  $payload['saveCardData'] = ['saveCard' => true];
}
error_log("MONO_CREATE payload webHookUrl=" . ($payload['webHookUrl'] ?? '') . " redirectUrl=" . ($payload['redirectUrl'] ?? ''));
// create invoice
$res  = mono_create_invoice($payload);
$code = (int)($res['code'] ?? 0);

if ($code < 200 || $code >= 300) {
  redirect('/account/index.php?tab=dashboard&err=' . rawurlencode('Mono create invoice error (HTTP ' . $code . ')'));
}

$data      = (array)($res['data'] ?? []);
$invoiceId = (string)($data['invoiceId'] ?? '');
$pageUrl   = (string)($data['pageUrl'] ?? '');

if ($invoiceId === '' || $pageUrl === '') {
  redirect('/account/index.php?tab=dashboard&err=' . rawurlencode('Mono invoice response invalid'));
}

// store invoice locally
mono_invoice_put([
  'invoice_id' => $invoiceId,
  'order_ref'  => $orderRef,
  'user_id'    => (string)$uid,
  'kind'       => ($action === 'trial') ? 'trial_hold' : 'plan_buy',
  'plan'       => $planKey, // basic|mini12
  'amount'     => $finalAmount,
  'status'     => 'created',
  'created_at' => gmdate('c'),
  'paid_at'    => null,
  'wallet_id'  => null,
  'meta'       => [
    'email' => (string)($u['email'] ?? ''),
    'name'  => (string)($u['name'] ?? ''),
  ],
]);

// ✅ pending plan for webhook to apply (NO base/12d!)
if ($action === 'trial') {
  $u['trial_pending_plan'] = $planKey; // basic|mini12
  user_upsert($u);
} else {
  $u['buy_pending_plan'] = $planKey;   // basic|mini12
  user_upsert($u);
}

// redirect to mono checkout page
redirect($pageUrl);