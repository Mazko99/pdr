<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/users_store.php';
require_once __DIR__ . '/../../src/mono.php';

// Захист: простий ключ (щоб ніхто не викликав ззовні)
$secret = mono_env('MONO_WEBHOOK_SECRET', '');
$got = (string)($_GET['k'] ?? '');
if ($secret === '' || !hash_equals($secret, $got)) {
  http_response_code(403);
  exit('forbidden');
}

$PRICE = [
  'base' => 69900,
  '12d'  => 38999,
];

$now = time();

$users = users_all();

foreach ($users as $u) {
  if (!is_array($u)) continue;

  $id = (string)($u['id'] ?? '');
  if ($id === '') continue;

  $trialUsed = (bool)($u['trial_used'] ?? false);
  if (!$trialUsed) continue;

  $cancelled = (bool)($u['trial_cancelled'] ?? false);
  if ($cancelled) continue;

  $charged = (bool)($u['trial_charged'] ?? false);
  if ($charged) continue;

  $trialExpires = (string)($u['trial_expires_at'] ?? '');
  if ($trialExpires === '') continue;

  $trialExpTs = strtotime($trialExpires);
  if (!$trialExpTs) continue;

  if ($now < $trialExpTs) continue;

  $plan = (string)($u['plan'] ?? 'base');
  if (!isset($PRICE[$plan])) $plan = 'base';

  $cardToken = (string)($u['mono_card_token'] ?? '');
  if ($cardToken === '') continue;

  $payload = [
    'cardToken' => $cardToken,
    'amount' => (int)$PRICE[$plan],
    'ccy' => mono_ccy(),
    'initiationKind' => 'merchant',
    'merchantPaymInfo' => [
      'reference' => 'trial_charge_' . $plan . '_' . $id . '_' . time(),
      'destination' => 'ProstoPDR: списання після trial (' . $plan . ')',
      'comment' => 'Trial charge',
    ],
    // ✅ правильний webhook
    'webHookUrl' => mono_app_url() . '/pay/mono_webhook.php',
    'redirectUrl' => mono_app_url() . '/account?tab=dashboard&pay=success',
  ];

  $r = mono_http('POST', '/api/merchant/wallet/payment', $payload);

  if ($r['code'] === 200) {
    if ($plan === '12d') {
      $u['expires_at'] = gmdate('c', time() + 12 * 86400);
    } else {
      $u['expires_at'] = gmdate('c', time() + 30 * 86400);
    }

    $u['trial_charged'] = true;
    $u['mono_last_payment_at'] = gmdate('c');

    user_upsert($u);
  }
}

echo "ok";