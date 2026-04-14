<?php
declare(strict_types=1);

require __DIR__ . '/../../../src/bootstrap.php';
require __DIR__ . '/../../../src/users_store.php';
require __DIR__ . '/../../../src/oauth_store.php';
require __DIR__ . '/../../../src/ref_store.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$code  = (string)($_GET['code'] ?? '');
$state = (string)($_GET['state'] ?? '');

if ($code === '' || $state === '') {
    redirect('/login?err=' . rawurlencode('Google login canceled'));
}

$expected = (string)($_SESSION['oauth_state_google'] ?? '');
$stateTime = (int)($_SESSION['oauth_state_google_time'] ?? 0);

if (
    $expected === '' ||
    !hash_equals($expected, $state) ||
    ($stateTime > 0 && (time() - $stateTime) > 900)
) {
    unset($_SESSION['oauth_state_google'], $_SESSION['oauth_state_google_time']);
    redirect('/login?err=' . rawurlencode('Google OAuth state mismatch'));
}

/* state валідний — тепер можна прибирати */
unset($_SESSION['oauth_state_google'], $_SESSION['oauth_state_google_time']);

$clientId = (string)env('GOOGLE_CLIENT_ID', '');
$secret   = (string)env('GOOGLE_CLIENT_SECRET', '');

if ($clientId === '' || $secret === '') {
    redirect('/login?err=' . rawurlencode('Google OAuth not configured'));
}

$host = (string)($_SERVER['HTTP_HOST'] ?? 'prostopdr.com');
$host = preg_replace('/^www\./i', '', $host);
$redirectUri = 'https://' . $host . '/auth/google/callback.php';

ref_ensure_schema();

$pendingGoogleRef = isset($_SESSION['oauth_google_ref_code']) && is_string($_SESSION['oauth_google_ref_code'])
    ? strtoupper(trim((string)$_SESSION['oauth_google_ref_code']))
    : '';

$pendingGoogleNext = isset($_SESSION['oauth_google_next']) && is_string($_SESSION['oauth_google_next'])
    ? trim((string)$_SESSION['oauth_google_next'])
    : '';

unset($_SESSION['oauth_google_ref_code'], $_SESSION['oauth_google_next']);

if ($pendingGoogleRef !== '') {
    ref_capture_code_from_request($pendingGoogleRef);
}

$token = http_post_form('https://oauth2.googleapis.com/token', [
    'code' => $code,
    'client_id' => $clientId,
    'client_secret' => $secret,
    'redirect_uri' => $redirectUri,
    'grant_type' => 'authorization_code',
]);

if (!is_array($token)) {
    redirect('/login?err=' . rawurlencode('Google token error'));
}

$accessToken = (string)($token['access_token'] ?? '');
if ($accessToken === '') {
    redirect('/login?err=' . rawurlencode('Google token error'));
}

$info = http_get_json('https://openidconnect.googleapis.com/v1/userinfo', [
    'Authorization: Bearer ' . $accessToken,
]);

if (!is_array($info)) {
    redirect('/login?err=' . rawurlencode('Google profile error'));
}

$email = (string)($info['email'] ?? '');
$sub   = (string)($info['sub'] ?? '');
$name  = (string)($info['name'] ?? '');

if ($email === '' || $sub === '') {
    redirect('/login?err=' . rawurlencode('Google profile error'));
}

$emailNorm = strtolower(trim($email));

$link = oauth_find('google', $sub);
if (is_array($link) && !empty($link['user_id'])) {
    $uid = (string)$link['user_id'];
    complete_login_google($uid, $pendingGoogleNext);
}

$u = user_find_by_email($emailNorm);
if (is_array($u) && !empty($u['id'])) {
    $uid = (string)$u['id'];
    oauth_link('google', $sub, $uid, $emailNorm, $name);
    complete_login_google($uid, $pendingGoogleNext);
}

$hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
$newId = (string)user_create($emailNorm, $name, $hash);

oauth_link('google', $sub, $newId, $emailNorm, $name);
ref_attach_new_user($newId);

complete_login_google($newId, $pendingGoogleNext);

function complete_login_google(string $uid, string $nextSafe = ''): void {
    auth_login($uid);

    /* Після успішного логіну можна оновити session id */
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    if (function_exists('session_register_current')) {
        session_register_current($uid, 'Google login');
    }

    if (function_exists('ds_on_login')) {
        $sid = session_status() === PHP_SESSION_ACTIVE ? session_id() : '';
        if ($sid !== '') {
            $res = ds_on_login($uid, $sid, 2);
            if (!($res['ok'] ?? false)) {
                auth_logout();
                redirect('/login?reason=max_devices');
            }
        }
    }

    auth_refresh_access();

    if ($nextSafe !== '' && str_starts_with($nextSafe, '/')) {
        redirect($nextSafe);
    }

    redirect('/account/index.php');
}

function http_post_form(string $url, array $fields): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 20,
    ]);
    $out = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($out) || $code < 200 || $code >= 300) {
        return null;
    }

    $json = json_decode($out, true);
    return is_array($json) ? $json : null;
}

function http_get_json(string $url, array $headers = []): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
    ]);
    $out = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($out) || $code < 200 || $code >= 300) {
        return null;
    }

    $json = json_decode($out, true);
    return is_array($json) ? $json : null;
}