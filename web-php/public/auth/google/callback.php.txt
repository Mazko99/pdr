<?php
declare(strict_types=1);

require __DIR__ . '/../../../src/bootstrap.php';
require __DIR__ . '/../../../src/users_store.php';
require __DIR__ . '/../../../src/oauth_store.php';

// bootstrap вже стартує сесію, але лишимо безпечно
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

$code  = (string)($_GET['code'] ?? '');
$state = (string)($_GET['state'] ?? '');

if ($code === '' || $state === '') {
  redirect('/login?err=' . rawurlencode('Google login canceled'));
}

// CSRF state check
$expected = (string)($_SESSION['oauth_state_google'] ?? '');
unset($_SESSION['oauth_state_google']);

if ($expected === '' || !hash_equals($expected, $state)) {
  redirect('/login?err=' . rawurlencode('Google OAuth state mismatch'));
}

$clientId = (string)env('GOOGLE_CLIENT_ID', '');
$secret   = (string)env('GOOGLE_CLIENT_SECRET', '');

if ($clientId === '' || $secret === '') {
  redirect('/login?err=' . rawurlencode('Google OAuth not configured'));
}

/**
 * ✅ redirect_uri MUST match what was used in start.php.
 * Робимо канонічно HTTPS + без www (як у start.php, який я тобі давав)
 */
$host = (string)($_SERVER['HTTP_HOST'] ?? 'prostopdr.com');
$host = preg_replace('/^www\./i', '', $host);
$redirectUri = 'https://' . $host . '/auth/google/callback.php';

// ---- обмен code -> token
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

// ---- get profile (userinfo)
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

// 1) if already linked by sub -> login
$link = oauth_find('google', $sub);
if (is_array($link) && !empty($link['user_id'])) {
  $uid = (string)$link['user_id'];
  complete_login_google($uid, $emailNorm, $name);
}

// 2) else: if user exists by email -> link and login
$u = user_find_by_email($emailNorm);
if (is_array($u) && !empty($u['id'])) {
  $uid = (string)$u['id'];
  oauth_link('google', $sub, $uid, $emailNorm, $name);
  complete_login_google($uid, $emailNorm, $name);
}

// 3) else: create new user + link + login
$hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
$newId = user_create($emailNorm, $name, $hash);
oauth_link('google', $sub, (string)$newId, $emailNorm, $name);
complete_login_google((string)$newId, $emailNorm, $name);


// ================= HELPERS =================

function complete_login_google(string $uid, string $email, string $name): void {
  // auth session
  auth_login($uid);

  // ✅ sessions.json (для адмінки/безпеки)
  if (function_exists('session_register_current')) {
    session_register_current($uid, 'Google login');
  }

  // ✅ Device policy (2 remembered + 1 active)
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

  // access
  auth_refresh_access();

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