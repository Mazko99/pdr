<?php
declare(strict_types=1);

require __DIR__ . '/../../../src/bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$clientId = (string)env('GOOGLE_CLIENT_ID', '');
$redirect = (string)env('GOOGLE_REDIRECT_URI', '');

if ($clientId === '' || $redirect === '') {
  http_response_code(500);
  exit('Google OAuth is not configured');
}

// CSRF state
$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state_google'] = $state;

$scope = rawurlencode('openid email profile');

$url =
  'https://accounts.google.com/o/oauth2/v2/auth'
  . '?response_type=code'
  . '&client_id=' . rawurlencode($clientId)
  . '&redirect_uri=' . rawurlencode($redirect)
  . '&scope=' . $scope
  . '&state=' . rawurlencode($state)
  . '&access_type=online'
  . '&prompt=select_account';

redirect($url);