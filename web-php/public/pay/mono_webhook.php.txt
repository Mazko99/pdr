<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/users_store.php';
require_once __DIR__ . '/../../src/mono.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

/**
 * Лог: спочатку пробуємо Railway volume /data,
 * якщо його немає — падаємо в web-php/storage
 */
function mono_log_file_path(): string {
    $candidates = [
        '/data',
        dirname(__DIR__, 2) . '/storage',
    ];

    foreach ($candidates as $dir) {
        if (is_dir($dir) || @mkdir($dir, 0775, true)) {
            return rtrim($dir, '/') . '/mono_webhook.log';
        }
    }

    return sys_get_temp_dir() . '/mono_webhook.log';
}

function log_line(string $line): void {
    $file = mono_log_file_path();
    $msg = '[' . date('c') . '] ' . $line;
    error_log('MONO_WEBHOOK ' . $msg);
    @file_put_contents($file, $msg . PHP_EOL, FILE_APPEND);
}

/**
 * Єдині коди планів у проекті:
 * - basic  = 30 днів
 * - mini12 = 12 днів
 */
function norm_plan(string $p): string {
    $p = trim(mb_strtolower($p));

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
        'trial12' => 'mini12',
    ];

    return $map[$p] ?? $p;
}

function extract_user_id_from_ref(string $ref): string {
    // numeric id
    if (preg_match('~(?:^|_)(\d{1,20})(?:_|$)~', $ref, $m)) {
        return (string)$m[1];
    }

    // hex-like id
    if (preg_match('~(?:^|_)([a-f0-9]{16,})(?:_|$)~i', $ref, $m)) {
        return (string)$m[1];
    }

    // guest id g_xxxxx...
    if (preg_match('~(?:^|_)(g_[a-z0-9]{8,})(?:_|$)~i', $ref, $m)) {
        return (string)$m[1];
    }

    return '';
}

// GET ping for browser/manual check
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    log_line('GET ping uri=' . ($_SERVER['REQUEST_URI'] ?? '') . ' ip=' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    http_response_code(200);
    echo 'OK (GET)';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    log_line('Method not allowed: ' . ($_SERVER['REQUEST_METHOD'] ?? 'unknown'));
    http_response_code(405);
    echo 'method not allowed';
    exit;
}

$raw = (string)file_get_contents('php://input');
log_line('RAW=' . $raw);

// headers
$headers = [];
foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_')) {
        $name = str_replace('_', '-', strtolower(substr($k, 5)));
        $headers[$name] = (string)$v;
    }
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    log_line('BAD JSON');
    http_response_code(400);
    echo 'bad json';
    exit;
}

/**
 * Коли все перевіриш і webhook стабільно запрацює —
 * розкоментуй verify.
 *
 * if (!mono_verify_webhook($raw, $headers)) {
 *     log_line('BAD SIGNATURE');
 *     http_response_code(403);
 *     echo 'bad signature';
 *     exit;
 * }
 */

$status    = (string)($data['status'] ?? '');
$invoiceId = (string)($data['invoiceId'] ?? '');
$amount    = (int)($data['amount'] ?? 0);
$cardToken = (string)($data['cardToken'] ?? '');

// reference fallback
$ref = (string)($data['reference'] ?? '');
if ($ref === '') $ref = (string)($data['orderId'] ?? '');

if ($ref === '' && isset($data['merchantPaymInfo']) && is_array($data['merchantPaymInfo'])) {
    $ref = (string)($data['merchantPaymInfo']['reference'] ?? '');
    if ($ref === '') {
        $ref = (string)($data['merchantPaymInfo']['orderId'] ?? '');
    }
}

log_line("PARSED status={$status} invoiceId={$invoiceId} amount={$amount} ref={$ref}");

$userId = '';
$mode   = '';
$plan   = '';

// buy_{plan}_{userId}_{ts}
if (str_starts_with($ref, 'buy_')) {
    $mode = 'buy';
    $parts = explode('_', $ref);
    $plan = (string)($parts[1] ?? '');
    $userId = (string)($parts[2] ?? '');
}
// trial_bind_{userId}_{ts}
elseif (str_starts_with($ref, 'trial_bind_')) {
    $mode = 'trial';
    $parts = explode('_', $ref);
    $userId = (string)($parts[2] ?? '');
}
// trial_charge_{plan}_{userId}_{ts}
elseif (str_starts_with($ref, 'trial_charge_')) {
    $mode = 'trial_charge';
    $parts = explode('_', $ref);
    $plan = (string)($parts[2] ?? '');
    $userId = (string)($parts[3] ?? '');
}
// fallback
elseif (str_contains($ref, 'trial')) {
    $mode = 'trial';
    $userId = extract_user_id_from_ref($ref);

    if (preg_match('~(?:^|_)(base|basic|personal|mini12|12d|12|30|30d|basic12|basic30)(?:_|$)~i', $ref, $m)) {
        $plan = (string)$m[1];
    }
}
elseif (str_contains($ref, 'checkout') || str_contains($ref, 'order')) {
    $mode = 'buy';
    $userId = extract_user_id_from_ref($ref);
}

$plan = norm_plan($plan);

log_line("MODE={$mode} userId={$userId} plan={$plan}");

if ($userId === '') {
    log_line('EXIT: NO USERID');
    http_response_code(200);
    echo 'ok';
    exit;
}

$u = user_find_by_id($userId);
if (!is_array($u)) {
    log_line("EXIT: USER NOT FOUND id={$userId}");
    http_response_code(200);
    echo 'ok';
    exit;
}

// created != paid
$okBuy   = ['success', 'processed', 'ok'];
$okTrial = ['success', 'processed', 'ok', 'hold', 'processing'];

if ($mode === 'trial' || $mode === 'trial_charge') {
    if (!in_array($status, $okTrial, true)) {
        log_line("EXIT: TRIAL status not ok ({$status})");
        http_response_code(200);
        echo 'ok';
        exit;
    }
} elseif ($mode === 'buy') {
    if (!in_array($status, $okBuy, true)) {
        log_line("EXIT: BUY status not ok ({$status})");
        http_response_code(200);
        echo 'ok';
        exit;
    }
} else {
    log_line("EXIT: UNKNOWN MODE ({$mode})");
    http_response_code(200);
    echo 'ok';
    exit;
}

// ============================
// BUY
// ============================
if ($mode === 'buy') {
    $chosen = $plan !== '' ? $plan : (string)($u['buy_pending_plan'] ?? 'basic');
    $chosen = norm_plan($chosen);
    if ($chosen !== 'mini12' && $chosen !== 'basic') {
        $chosen = 'basic';
    }

    $days = ($chosen === 'mini12') ? 12 : 30;

    $u['plan'] = $chosen;
    $u['expires_at'] = gmdate('c', time() + $days * 86400);
    $u['paid_at'] = gmdate('c');
    $u['plan_set_at'] = gmdate('c');
    $u['mono_last_payment_at'] = gmdate('c');

    $u['buy_pending_invoice'] = null;
    $u['buy_pending_plan'] = null;

    user_upsert($u);

    log_line("BUY OK -> plan={$u['plan']} expires_at={$u['expires_at']}");
    http_response_code(200);
    echo 'ok';
    exit;
}

// ============================
// TRIAL
// ============================
if ($mode === 'trial') {
    $pendingPlan = norm_plan((string)($u['trial_pending_plan'] ?? 'basic'));
    if ($pendingPlan !== 'basic' && $pendingPlan !== 'mini12') {
        $pendingPlan = 'basic';
    }

    if ($plan === 'basic' || $plan === 'mini12') {
        $pendingPlan = $plan;
    }

    $u['trial_used'] = true;
    $u['trial_started_at'] = (string)($u['trial_started_at'] ?? gmdate('c'));
    $u['trial_expires_at'] = gmdate('c', time() + 3 * 86400);
    $u['trial_cancelled'] = false;

    $u['plan'] = $pendingPlan;
    $u['expires_at'] = (string)$u['trial_expires_at'];
    $u['paid_at'] = gmdate('c');
    $u['plan_set_at'] = gmdate('c');

    if ($cardToken !== '') {
        $u['mono_card_token'] = $cardToken;
    }

    $u['trial_pending_invoice'] = null;

    user_upsert($u);

    log_line("TRIAL OK -> plan={$u['plan']} trial_expires_at={$u['trial_expires_at']}");
    http_response_code(200);
    echo 'ok';
    exit;
}

// ============================
// TRIAL_CHARGE
// ============================
if ($mode === 'trial_charge') {
    $chosen = $plan !== '' ? $plan : (string)($u['trial_pending_plan'] ?? 'basic');
    $chosen = norm_plan($chosen);
    if ($chosen !== 'mini12' && $chosen !== 'basic') {
        $chosen = 'basic';
    }

    $days = ($chosen === 'mini12') ? 12 : 30;

    $u['plan'] = $chosen;
    $u['expires_at'] = gmdate('c', time() + $days * 86400);
    $u['paid_at'] = gmdate('c');
    $u['plan_set_at'] = gmdate('c');
    $u['mono_last_payment_at'] = gmdate('c');

    user_upsert($u);

    log_line("TRIAL_CHARGE OK -> plan={$u['plan']} expires_at={$u['expires_at']}");
    http_response_code(200);
    echo 'ok';
    exit;
}

log_line('EXIT: FALLTHROUGH');
http_response_code(200);
echo 'ok';