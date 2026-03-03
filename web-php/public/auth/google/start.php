<?php
declare(strict_types=1);

require __DIR__ . '/../../../src/bootstrap.php';

// bootstrap вже стартує сесію, але залишимо безпечно
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

$clientId = (string)env('GOOGLE_CLIENT_ID', '');
if ($clientId === '') {
  http_response_code(500);
  exit('Google OAuth is not configured: GOOGLE_CLIENT_ID is empty');
}

// ✅ Канонічний хост: без www
$host = (string)($_SERVER['HTTP_HOST'] ?? 'prostopdr.com');
$host = preg_replace('/^www\./i', '', $host);

// ✅ Канонічний протокол: HTTPS (Railway/Cloudflare)
$proto = 'https';

// ✅ Redirect URI рахуємо самі, щоб не було http/https mismatch
$redirect = $proto . '://' . $host . '/auth/google/callback.php';

// CSRF state
$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state_google'] = $state;

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