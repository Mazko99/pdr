<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/users_store.php';
require_once __DIR__ . '/../../src/mono.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

function checkout_fail(string $url, string $reason): void {
    header('Location: ' . $url . '&reason=' . rawurlencode($reason), true, 302);
    exit;
}

function norm_checkout_plan(string $raw): string {
    $raw = trim(mb_strtolower($raw));

    $map = [
        '30' => 'basic',
        '30d' => 'basic',
        'base' => 'basic',
        'basic' => 'basic',
        'basic30' => 'basic',

        '12' => 'mini12',
        '12d' => 'mini12',
        'personal' => 'mini12',
        'mini12' => 'mini12',
        'basic12' => 'mini12',
    ];

    return $map[$raw] ?? 'basic';
}

$userId = (string)($_SESSION['user_id'] ?? '');
$isAuth = ($userId !== '');

// підтримка і POST, і GET
$modeRaw = (string)($_POST['action'] ?? $_GET['mode'] ?? 'buy');
$planRaw = (string)($_POST['plan'] ?? $_GET['plan'] ?? '30');

$mode = ($modeRaw === 'trial') ? 'trial' : 'buy';
$plan = norm_checkout_plan($planRaw);

$PRICE = [
    'basic'  => 69900,
    'mini12' => 38999,
];

$app = mono_app_url();
if ($app === '') {
    http_response_code(500);
    exit('APP_URL missing');
}

$successUrl = $app . '/account?tab=dashboard&pay=success';
$failUrl    = $app . '/account?tab=dashboard&pay=fail';
$webhookUrl = $app . '/pay/mono_webhook.php';

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
        'paid_at' => null,
        'expires_at' => null,
        'created_at' => gmdate('c'),
    ];
    user_upsert($u);
}

$trialUsed = !empty($u['trial_used']);

if ($mode === 'trial') {
    if ($trialUsed) {
        checkout_fail($failUrl, 'trial_used');
    }

    $holdAmount = (int)mono_env('MONO_TRIAL_HOLD_AMOUNT', '100');
    if ($holdAmount < 1) {
        $holdAmount = 100;
    }

    $payload = [
        'amount' => $holdAmount,
        'ccy'    => mono_ccy(),
        'merchantPaymInfo' => [
            'reference'   => 'trial_bind_' . $userId . '_' . time(),
            'destination' => 'ProstoPDR: привʼязка картки для trial',
            'comment'     => 'Trial bind',
        ],
        'redirectUrl' => $successUrl,
        'webHookUrl'  => $webhookUrl,
        'saveCardData' => [
            'saveCard' => true,
        ],
    ];

    try {
        $r = mono_http('POST', '/api/merchant/invoice/create', $payload);
    } catch (Throwable $e) {
        checkout_fail($failUrl, 'mono_exception');
    }

    if ((int)$r['code'] !== 200) {
        checkout_fail($failUrl, 'mono_' . (string)$r['code']);
    }

    $pageUrl = (string)($r['data']['pageUrl'] ?? '');
    $invoiceId = (string)($r['data']['invoiceId'] ?? '');

    if ($pageUrl === '' || $invoiceId === '') {
        checkout_fail($failUrl, 'mono_bad_response');
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
$amount = (int)($PRICE[$plan] ?? 69900);

$payload = [
    'amount' => $amount,
    'ccy'    => mono_ccy(),
    'merchantPaymInfo' => [
        'reference'   => 'buy_' . $plan . '_' . $userId . '_' . time(),
        'destination' => 'ProstoPDR: покупка плану ' . $plan,
        'comment'     => 'Buy ' . $plan,
    ],
    'redirectUrl' => $successUrl,
    'webHookUrl'  => $webhookUrl,
];

try {
    $r = mono_http('POST', '/api/merchant/invoice/create', $payload);
} catch (Throwable $e) {
    checkout_fail($failUrl, 'mono_exception');
}

if ((int)$r['code'] !== 200) {
    checkout_fail($failUrl, 'mono_' . (string)$r['code']);
}

$pageUrl = (string)($r['data']['pageUrl'] ?? '');
$invoiceId = (string)($r['data']['invoiceId'] ?? '');

if ($pageUrl === '' || $invoiceId === '') {
    checkout_fail($failUrl, 'mono_bad_response');
}

$u['buy_pending_invoice'] = $invoiceId;
$u['buy_pending_plan'] = $plan;
user_upsert($u);

header('Location: ' . $pageUrl, true, 302);
exit;