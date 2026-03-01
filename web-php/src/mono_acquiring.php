<?php
declare(strict_types=1);

/**
 * Mono acquiring helper (minimal)
 * Uses env:
 * - MONO_MERCHANT_TOKEN
 * - MONO_WEBHOOK_URL
 * - MONO_RETURN_URL
 * - MONO_PUBLIC_KEY_CACHE
 */

function mono_env(string $key, string $default=''): string {
  $v = getenv($key);
  if ($v === false || $v === null || trim((string)$v) === '') return $default;
  return trim((string)$v);
}

function mono_http_json(string $method, string $url, ?array $body = null, array $headers = []): array {
  $ch = curl_init($url);
  $baseHeaders = [
    'Accept: application/json',
  ];
  $headers = array_merge($baseHeaders, $headers);

  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_TIMEOUT => 25,
  ]);

  if ($body !== null) {
    $json = json_encode($body, JSON_UNESCAPED_UNICODE);
    if ($json === false) $json = '{}';
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
  }

  $out = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);

  if (!is_string($out)) $out = '';
  $data = json_decode($out, true);
  if (!is_array($data)) $data = [];

  return ['code'=>$code, 'data'=>$data, 'raw'=>$out, 'err'=>$err];
}

function mono_token(): string {
  $t = mono_env('MONO_MERCHANT_TOKEN', '');
  if ($t === '') throw new RuntimeException('MONO_MERCHANT_TOKEN is empty');
  return $t;
}

/**
 * Create invoice
 * Docs: https://monobank.ua/api-docs/acquiring/  (create invoice endpoint)
 */
function mono_create_invoice(array $payload): array {
  $token = mono_token();
  $res = mono_http_json(
    'POST',
    'https://api.monobank.ua/api/merchant/invoice/create',
    $payload,
    [
      'Content-Type: application/json',
      'X-Token: ' . $token,
    ]
  );
  return $res;
}

function mono_get_invoice_status(string $invoiceId): array {
  $token = mono_token();
  $res = mono_http_json(
    'GET',
    'https://api.monobank.ua/api/merchant/invoice/status?invoiceId=' . rawurlencode($invoiceId),
    null,
    [
      'X-Token: ' . $token,
    ]
  );
  return $res;
}

/**
 * Pay by token (wallet payment)
 * Docs: https://monobank.ua/api-docs/acquiring/extras/tokens/post--api--merchant--wallet--payment :contentReference[oaicite:3]{index=3}
 */
function mono_wallet_payment(array $payload): array {
  $token = mono_token();
  $res = mono_http_json(
    'POST',
    'https://api.monobank.ua/api/merchant/wallet/payment',
    $payload,
    [
      'Content-Type: application/json',
      'X-Token: ' . $token,
    ]
  );
  return $res;
}

/**
 * Webhook verify:
 * Docs: https://monobank.ua/api-docs/acquiring/dev/webhooks/verify :contentReference[oaicite:4]{index=4}
 *
 * Mono sends headers:
 * - X-Sign
 * - X-Time
 * Body is used in signature
 */
function mono_public_key_path(): string {
  $p = mono_env('MONO_PUBLIC_KEY_CACHE', 'storage/mono_public.pem');
  // allow relative to project root (src is /src)
  if (!str_starts_with($p, '/')) {
    $p = dirname(__DIR__) . '/' . ltrim($p, '/');
  }
  return $p;
}

function mono_fetch_public_key_and_cache(): bool {
  $res = mono_http_json('GET', 'https://api.monobank.ua/api/merchant/pubkey', null, [
    'X-Token: ' . mono_token(),
  ]);
  if ($res['code'] !== 200) return false;

  $pem = (string)($res['raw'] ?? '');
  if (trim($pem) === '') return false;

  $path = mono_public_key_path();
  $dir = dirname($path);
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  file_put_contents($path, $pem);
  return true;
}

function mono_verify_webhook(string $rawBody, array $headersLower): bool {
  $xSign = (string)($headersLower['x-sign'] ?? '');
  $xTime = (string)($headersLower['x-time'] ?? '');

  if ($xSign === '' || $xTime === '') return false;

  $pemPath = mono_public_key_path();
  if (!is_file($pemPath)) {
    // try auto-fetch once
    mono_fetch_public_key_and_cache();
  }
  if (!is_file($pemPath)) return false;

  $pubKeyPem = (string)file_get_contents($pemPath);
  if (trim($pubKeyPem) === '') return false;

  // signature is base64
  $sig = base64_decode($xSign, true);
  if ($sig === false) return false;

  // According to docs: data is X-Time + body
  $data = $xTime . $rawBody;

  $ok = openssl_verify($data, $sig, $pubKeyPem, OPENSSL_ALGO_SHA256);
  return $ok === 1;
}