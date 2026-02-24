<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method not allowed');
}

csrf_verify($_POST['csrf'] ?? null);

$credential = (string)($_POST['credential'] ?? '');
if ($credential === '') {
  redirect('/login?err=' . rawurlencode('Google: порожній токен'));
}

$clientId = env('GOOGLE_CLIENT_ID', '');
if (!$clientId) {
  redirect('/login?err=' . rawurlencode('Google-вхід не налаштований. Додай GOOGLE_CLIENT_ID у .env'));
}

$tokenInfoUrl = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($credential);
$context = stream_context_create([
  'http' => [
    'timeout' => 8,
    'ignore_errors' => true,
    'header' => "Accept: application/json\r\n",
  ],
]);

$resp = @file_get_contents($tokenInfoUrl, false, $context);
if ($resp === false) {
  redirect('/login?err=' . rawurlencode('Google: не вдалося перевірити токен'));
}

$data = json_decode($resp, true);
if (!is_array($data)) {
  redirect('/login?err=' . rawurlencode('Google: некоректна відповідь перевірки'));
}

$aud = (string)($data['aud'] ?? '');
if ($aud !== $clientId) {
  redirect('/login?err=' . rawurlencode('Google: токен не для цього клієнта (aud mismatch)'));
}

$email = (string)($data['email'] ?? '');
$sub   = (string)($data['sub'] ?? '');
$name  = (string)($data['name'] ?? '');

if ($email === '' || $sub === '') {
  redirect('/login?err=' . rawurlencode('Google: не отримали email/sub'));
}

$pdo = db();
$uid = db_upsert_user_google($pdo, $email, $name, $sub);

auth_login($uid);
redirect('/?ok=' . rawurlencode('Вхід через Google виконано!'));
