<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/users_store.php';
require_once __DIR__ . '/../src/mono_acquiring.php';
require_once __DIR__ . '/../src/mono_payments_store.php';

$due = mono_trials_due(time());
if (empty($due)) {
  echo "No due trials\n";
  exit;
}

foreach ($due as $t) {
  $uid = (string)($t['user_id'] ?? '');
  $plan = (string)($t['plan'] ?? '30');
  $amount = (int)($t['charge_amount'] ?? 0);
  $walletId = (string)($t['wallet_id'] ?? '');

  if ($uid === '' || $amount <= 0 || $walletId === '') {
    // mark failed (no token)
    $t['status'] = 'failed';
    mono_trial_set($t);
    echo "Trial failed uid={$uid} (no walletId)\n";
    continue;
  }

  // charge
  $orderId = 'trial_charge_u' . $uid . '_' . time();

  $payload = [
    'walletId' => $walletId,
    'amount' => $amount,
    'ccy' => 980,
    'merchantPaymInfo' => [
      'reference' => $orderId,
      'destination' => 'Списання після тріалу — план ' . $plan,
      'comment' => 'One-time after trial',
      'basketOrder' => [
        [
          'name' => 'План ' . $plan,
          'qty' => 1,
          'sum' => $amount,
          'code' => $plan,
          'unit' => 'шт',
        ]
      ],
    ],
  ];

  $res = mono_wallet_payment($payload);
  $ok = (($res['code'] ?? 0) >= 200 && ($res['code'] ?? 0) < 300);

  if ($ok) {
    $t['status'] = 'charged';
    mono_trial_set($t);

    // grant plan days
    $days = ($plan === '12') ? 12 : 30;
    user_set_plan($uid, ($plan === '12') ? 'basic12' : 'basic30', $days);

    echo "Charged and granted uid={$uid} plan={$plan}\n";
  } else {
    $t['status'] = 'failed';
    mono_trial_set($t);
    echo "Charge failed uid={$uid} code=" . ($res['code'] ?? 0) . "\n";
  }
}