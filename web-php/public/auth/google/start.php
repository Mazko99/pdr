<?php
declare(strict_types=1);

require __DIR__ . '/../../../src/bootstrap.php';
require __DIR__ . '/../../../src/ref_store.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$clientId = (string)env('GOOGLE_CLIENT_ID', '');
if ($clientId === '') {
    http_response_code(500);
    exit('Google OAuth is not configured: GOOGLE_CLIENT_ID is empty');
}

$ref = strtoupper(trim((string)($_GET['ref'] ?? '')));
$next = trim((string)($_GET['next'] ?? ''));

ref_ensure_schema();

if ($ref !== '') {
    ref_capture_code_from_request($ref);
    $_SESSION['oauth_google_ref_code'] = $ref;
} else {
    unset($_SESSION['oauth_google_ref_code']);
}

if ($next !== '' && str_starts_with($next, '/')) {
    $_SESSION['oauth_google_next'] = $next;
} else {
    unset($_SESSION['oauth_google_next']);
}

$host = (string)($_SERVER['HTTP_HOST'] ?? 'prostopdr.com');
$host = preg_replace('/^www\./i', '', $host);

$proto = 'https';
$redirect = $proto . '://' . $host . '/auth/google/callback.php';

$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state_google'] = $state;
$_SESSION['oauth_state_google_time'] = time();

/* ВАЖЛИВО: примусово записати сесію перед редіректом на Google */
session_write_close();

$scope = 'openid email profile';

$url =
    'https://accounts.google.com/o/oauth2/v2/auth'
    . '?response_type=code'
    . '&client_id=' . rawurlencode($clientId)
    . '&redirect_uri=' . rawurlencode($redirect)
    . '&scope=' . rawurlencode($scope)
    . '&state=' . rawurlencode($state)
    . '&access_type=online'
    . '&prompt=select_account';

redirect($url);