<?php
declare(strict_types=1);

require __DIR__ . '/../../../src/bootstrap.php';
require __DIR__ . '/../../../src/users_store.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$code  = (string)($_GET['code'] ?? '');
$state = (string)($_GET['state'] ?? '');

if ($code === '' || $state === '') {
  redirect('/login?err=' . rawurlencode('Google login canceled'));
}

$expected = (string)($_SESSION['oauth_state_google'] ?? '');
unset($_SESSION['oauth_state_google']);

if (!hash_equals($expected, $state)) {
  redirect('/login?err=' . rawurlencode('Google OAuth state mismatch'));
}

$clientId = (string)env('GOOGLE_CLIENT_ID', '');
$secret   = (string)env('GOOGLE_CLIENT_SECRET', '');
$redirectUri = (string)env('GOOGLE_REDIRECT_URI', '');

if ($clientId === '' || $secret === '' || $redirectUri === '') {
  redirect('/login?err=' . rawurlencode('Google OAuth not configured'));
}

// обмен code -> token
$token = http_post_form('https://oauth2.googleapis.com/token', [
  'code' => $code,
  'client_id' => $clientId,
  'client_secret' => $secret,
  'redirect_uri' => $redirectUri,
  'grant_type' => 'authorization_code',
]);

if (!is_array($token) || empty($token['id_token'])) {
  redirect('/login?err=' . rawurlencode('Google token error'));
}

$accessToken = (string)($token['access_token'] ?? '');
if ($accessToken === '') {
  redirect('/login?err=' . rawurlencode('Google token error'));
}

// получить profile по userinfo (проще чем руками валидировать JWT)
$info = http_get_json('https://openidconnect.googleapis.com/v1/userinfo', [
  'Authorization: Bearer ' . $accessToken,
]);

$email = (string)($info['email'] ?? '');
$sub   = (string)($info['sub'] ?? '');
$name  = (string)($info['name'] ?? '');

if ($email === '' || $sub === '') {
  redirect('/login?err=' . rawurlencode('Google profile error'));
}

// 1) если уже привязан sub -> логиним
$link = oauth_find('google', $sub);
if ($link && !empty($link['user_id'])) {
  $uid = (string)$link['user_id'];

  auth_login($uid);

  // ✅ ДОДАНО: реєстрація сеансу
  if (function_exists('session_register_current')) {
    session_register_current($uid, 'Google login');
  }

  auth_refresh_access();
  redirect('/account/index.php');
}

// 2) иначе: если есть пользователь по email — привязать к нему
$u = user_find_by_email($email);
if ($u && !empty($u['id'])) {
  $uid = (string)$u['id'];

  oauth_link('google', $sub, $uid);
  auth_login($uid);

  // ✅ ДОДАНО: реєстрація сеансу
  if (function_exists('session_register_current')) {
    session_register_current($uid, 'Google login');
  }

  auth_refresh_access();
  redirect('/account/index.php');
}

// 3) иначе: создать нового пользователя (пароль пустой/рандом)
$hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
$newId = user_create($email, $name, $hash);

oauth_link('google', $sub, (string)$newId);

auth_login((string)$newId);

// ✅ ДОДАНО: реєстрація сеансу
if (function_exists('session_register_current')) {
  session_register_current((string)$newId, 'Google login');
}

auth_refresh_access();
redirect('/account/index.php');


// ---- helpers ----
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

  if (!is_string($out) || $code < 200 || $code >= 300) return null;
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

  if (!is_string($out) || $code < 200 || $code >= 300) return null;
  $json = json_decode($out, true);
  return is_array($json) ? $json : null;
}