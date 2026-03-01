<?php
declare(strict_types=1);

function mono_env(string $k, string $def = ''): string {
  $v = getenv($k);
  if (is_string($v) && $v !== '') return $v;

  // fallback: читаємо .env руками (корінь проєкту)
  $envPath = dirname(__DIR__) . '/.env';
  if (!is_file($envPath)) return $def;

  $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if (!is_array($lines)) return $def;

  foreach ($lines as $line) {
    $line = trim((string)$line);
    if ($line === '' || str_starts_with($line, '#')) continue;
    $pos = strpos($line, '=');
    if ($pos === false) continue;

    $key = trim(substr($line, 0, $pos));
    if ($key !== $k) continue;

    $val = trim(substr($line, $pos + 1));
    if (strlen($val) >= 2) {
      $first = $val[0];
      $last  = $val[strlen($val) - 1];
      if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
        $val = substr($val, 1, -1);
      }
    }
    return $val;
  }

  return $def;
}

function mono_token(): string { return trim(mono_env('MONO_X_TOKEN')); }
function mono_ccy(): int { return (int)(mono_env('MONO_CCY', '980')); }
function mono_app_url(): string { return rtrim(mono_env('APP_URL', ''), '/'); }

function mono_http(string $method, string $path, ?array $payload = null): array {
  $base = 'https://api.monobank.ua';
  $url = $base . $path;

  $token = mono_token();
  if ($token === '') throw new RuntimeException('MONO_X_TOKEN is empty');

  $ch = curl_init($url);
  if ($ch === false) throw new RuntimeException('curl_init failed');

  $headers = [
    'X-Token: ' . $token,
    'Content-Type: application/json',
  ];

  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

  if ($payload !== null) {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) throw new RuntimeException('json_encode failed');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
  }

  $raw = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);

  if ($raw === false) {
    throw new RuntimeException('curl_exec failed: ' . $err);
  }

  $data = json_decode((string)$raw, true);
  if (!is_array($data)) $data = ['_raw' => (string)$raw];

  return ['code' => $code, 'data' => $data];
}

/**
 * Отримати pubkey (для перевірки підпису вебхуків)
 * GET /api/merchant/pubkey
 */
function mono_get_pubkey(): string {
  $r = mono_http('GET', '/api/merchant/pubkey', null);
  if ($r['code'] !== 200) {
    throw new RuntimeException('mono pubkey http ' . $r['code'] . ': ' . json_encode($r['data']));
  }
  $key = (string)($r['data']['key'] ?? '');
  if ($key === '') throw new RuntimeException('mono pubkey empty');
  return $key;
}

/**
 * Перевірка підпису webhook (mono-sign / x-signature залежить від варіанту)
 * У mono є приклади верифікації у Webhooks. :contentReference[oaicite:2]{index=2}
 */
function mono_verify_webhook(string $rawBody, array $headers): bool {
  // Моно зазвичай шле X-Signature / Mono-Signature (може відрізнятися)
  $sig = '';
  foreach ($headers as $k => $v) {
    $lk = strtolower((string)$k);
    if ($lk === 'x-signature' || $lk === 'mono-signature' || $lk === 'monobank-signature') {
      $sig = is_array($v) ? (string)($v[0] ?? '') : (string)$v;
      break;
    }
  }
  if ($sig === '') return false;

  $pubkey = mono_get_pubkey();
  $pub = "-----BEGIN PUBLIC KEY-----\n" . chunk_split($pubkey, 64, "\n") . "-----END PUBLIC KEY-----\n";

  $ok = openssl_verify($rawBody, base64_decode($sig), $pub, OPENSSL_ALGO_SHA256);
  return $ok === 1;
}