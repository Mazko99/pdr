<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/users_store.php';
require_once __DIR__ . '/../../src/mono_acquiring.php';
require_once __DIR__ . '/../../src/mono_payments_store.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
  @session_start();
}

/**
 * ✅ GET bridge:
 * Дозволяє робити href типу:
 *  /pay/create.php?action=trial&plan=12
 *  /pay/create.php?action=buy&plan=30
 *
 * Щоб не відкривали оплату боти — дозволяємо тільки якщо користувач залогінений.
 */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
  $uid = auth_user_id();
  if ($uid === null || $uid === '') {
    redirect('/login?err=' . rawurlencode('Спочатку увійди в акаунт, щоб оформити оплату.'));
  }

  $gAction = (string)($_GET['action'] ?? '');
  $gPlan   = (string)($_GET['plan'] ?? '');

  if (!in_array($gAction, ['buy', 'trial'], true)) {
    redirect('/account?tab=dashboard&err=' . rawurlencode('Некоректний action.'));
  }
  if (!in_array($gPlan, ['12', '30'], true)) {
    redirect('/account?tab=dashboard&err=' . rawurlencode('Некоректний plan.'));
  }

  // Підкладаємо в POST і "імітуємо" POST
  $_POST['action'] = $gAction;
  $_POST['plan']   = $gPlan;
  $_SERVER['REQUEST_METHOD'] = 'POST';
}

// ✅ Тільки POST (після GET-містка тут теж буде POST)
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  exit('Method not allowed');
}

// ✅ CSRF перевіряємо тільки якщо реально прийшов csrf (тобто з форми)
if (isset($_POST['csrf']) && (string)$_POST['csrf'] !== '') {
  csrf_verify($_POST['csrf']);
}

$action = (string)($_POST['action'] ?? 'buy'); // buy|trial
$plan   = (string)($_POST['plan'] ?? '30');    // 12|30

if (!in_array($action, ['buy', 'trial'], true)) {
  redirect('/account?tab=dashboard&err=' . rawurlencode('Некоректний action.'));
}

if ($plan !== '12' && $plan !== '30') {
  $plan = '30';
}

// uid
$uid = auth_user_id();
if ($uid === null || $uid === '') {
  redirect('/login?err=' . rawurlencode('Спочатку увійди в акаунт, щоб оформити оплату.'));
}

// user
$u = user_find_by_id((string)$uid);
if (!is_array($u)) {
  redirect('/login?err=' . rawurlencode('Акаунт не знайдено.'));
}

// amounts in kop
$amount12  = (int)getenv('PLAN_12_AMOUNT');
$amount30  = (int)getenv('PLAN_30_AMOUNT');
$trialHold = (int)getenv('TRIAL_HOLD_AMOUNT');
$trialDays = (int)getenv('TRIAL_DAYS');
if ($trialDays <= 0) $trialDays = 3;

$amount = ($plan === '12') ? $amount12 : $amount30;
if ($amount <= 0) $amount = ($plan === '12') ? 38999 : 69900;

if ($trialHold <= 0) $trialHold = 100;

// URLs from ENV
$returnUrl  = mono_env('MONO_RETURN_URL', '');
$webhookUrl = mono_env('MONO_WEBHOOK_URL', '');

if ($returnUrl === '' || $webhookUrl === '') {
  redirect('/account?tab=dashboard&err=' . rawurlencode('Mono не налаштовано: RETURN/WEBHOOK URL'));
}

// planCode для webhook: base|12d
$planCode = ($plan === '12') ? '12d' : 'base';

// reference у форматі, який вміє mono_webhook.php:
if ($action === 'trial') {
  // trial_bind_{userId}_{ts}
  $orderId = 'trial_bind_' . $uid . '_' . time();
} else {
  // buy_{planCode}_{userId}_{ts}
  $orderId = 'buy_' . $planCode . '_' . $uid . '_' . time();
}

$title = ($action === 'trial')
  ? ('Тріал ' . $trialDays . ' дні + прив’язка картки (1 грн) — План ' . $plan)
  : ('Оплата плану ' . $plan);

$finalAmount = ($action === 'trial') ? $trialHold : $amount;

$payload = [
  'amount' => $finalAmount,
  'ccy' => 980,
  'merchantPaymInfo' => [
    'reference' => $orderId,
    'destination' => $title,
    'comment' => $title,
    'basketOrder' => [
      [
        'name' => $title,
        'qty' => 1,
        'sum' => $finalAmount,
        'code' => $plan,
        'icon' => '',
        'unit' => 'шт',
      ]
    ],
  ],
  'redirectUrl' => $returnUrl . '&invoice=' . rawurlencode($orderId),
  'webHookUrl'  => $webhookUrl,
];

// Якщо trial — попросимо збереження картки
if ($action === 'trial') {
  $payload['saveCardData'] = ['saveCard' => true];
}

// create invoice
$res = mono_create_invoice($payload);
$code = (int)($res['code'] ?? 0);
if ($code < 200 || $code >= 300) {
  redirect('/account?tab=dashboard&err=' . rawurlencode('Mono create invoice error'));
}

$data = (array)($res['data'] ?? []);
$invoiceId = (string)($data['invoiceId'] ?? '');
$pageUrl   = (string)($data['pageUrl'] ?? '');

if ($invoiceId === '' || $pageUrl === '') {
  redirect('/account?tab=dashboard&err=' . rawurlencode('Mono invoice response invalid'));
}

// store
mono_invoice_put([
  'invoice_id' => $invoiceId,
  'order_ref'  => $orderId,
  'user_id'    => (string)$uid,
  'kind'       => ($action === 'trial') ? 'trial_hold' : 'plan_buy',
  'plan'       => $planCode, // base|12d
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

// pending plan (щоб webhook видав правильний план)
if ($action === 'trial') {
  $u['trial_pending_plan'] = $planCode; // base|12d
  user_upsert($u);
} else {
  $u['buy_pending_plan'] = $planCode; // base|12d
  user_upsert($u);
}

// redirect to mono payment page
header('Location: ' . $pageUrl, true, 302);
exit;