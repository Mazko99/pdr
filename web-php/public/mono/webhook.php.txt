<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/users_store.php';
require_once __DIR__ . '/../../src/mono_acquiring.php';
require_once __DIR__ . '/../../src/mono_payments_store.php';

if (session_status() !== PHP_SESSION_ACTIVE) @session_start();

$raw = (string)file_get_contents('php://input');

// headers lower
$headersLower = [];
foreach (getallheaders() as $k => $v) {
  $headersLower[strtolower((string)$k)] = (string)$v;
}

// verify signature
if (!mono_verify_webhook($raw, $headersLower)) {
  http_response_code(401);
  echo 'bad signature';
  exit;
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
  http_response_code(400);
  echo 'bad json';
  exit;
}

$invoiceId = (string)($payload['invoiceId'] ?? '');
$status    = (string)($payload['status'] ?? ''); // e.g. "success"
if ($invoiceId === '') {
  http_response_code(400);
  echo 'no invoiceId';
  exit;
}

// load local invoice record
$inv = mono_invoice_get($invoiceId);
if (!is_array($inv)) {
  // unknown invoice - still OK
  http_response_code(200);
  echo 'ok';
  exit;
}

// update status
$inv['status'] = ($status !== '') ? $status : (string)($inv['status'] ?? 'created');
if (($status === 'success' || $status === 'paid') && empty($inv['paid_at'])) {
  $inv['paid_at'] = gmdate('c');
}

// walletId/token may appear in webhook payload depending on mono settings
$walletId = (string)($payload['walletId'] ?? $payload['cardToken'] ?? '');
if ($walletId !== '') {
  $inv['wallet_id'] = $walletId;
}

mono_invoice_put($inv);

// if paid -> apply logic
if ($status === 'success' || $status === 'paid') {
  $uid = (string)($inv['user_id'] ?? '');
  $plan = (string)($inv['plan'] ?? '30');

  if ($uid !== '') {
    // TRIAL HOLD -> give 3 days + schedule charge
    if ((string)($inv['kind'] ?? '') === 'trial_hold') {
      $trialDays = (int)getenv('TRIAL_DAYS');
      if ($trialDays <= 0) $trialDays = 3;

      // grant temporary access for trialDays (plan=trial)
      user_set_plan($uid, 'trial', $trialDays);

      // schedule real charge after trial
      $amount12 = (int)getenv('PLAN_12_AMOUNT');
      $amount30 = (int)getenv('PLAN_30_AMOUNT');
      $chargeAmount = ($plan === '12') ? $amount12 : $amount30;
      if ($chargeAmount <= 0) $chargeAmount = ($plan === '12') ? 38999 : 69900;

      $dueAt = time() + $trialDays * 86400;

      mono_trial_set([
        'user_id' => $uid,
        'plan' => $plan,
        'charge_amount' => $chargeAmount,
        'wallet_id' => (string)($inv['wallet_id'] ?? ''),
        'due_at' => $dueAt,
        'status' => 'pending',
        'created_at' => gmdate('c'),
      ]);
    }

    // Direct plan buy -> set plan for 12/30 days
    if ((string)($inv['kind'] ?? '') === 'plan_buy') {
      $days = ($plan === '12') ? 12 : 30;
      user_set_plan($uid, ($plan === '12') ? 'basic12' : 'basic30', $days);
      // NOTE: no auto-renew
    }
  }
}

http_response_code(200);
echo 'ok';