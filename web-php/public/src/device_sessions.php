<?php
declare(strict_types=1);

/**
 * Device + Session policy:
 * - Remembered devices: max 2 per user
 * - Only 1 active session at a time
 *
 * Storage: /storage/device_sessions.json
 * Cookie:  ppdr_device_id (365 days)
 */

function ds_path(): string {
  return dirname(__DIR__) . '/storage/device_sessions.json';
}

function ds_load(): array {
  $p = ds_path();
  if (!is_file($p)) return ['users' => []];
  $raw = file_get_contents($p);
  if (!is_string($raw) || $raw === '') return ['users' => []];

  if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);

  $data = json_decode($raw, true);
  if (!is_array($data)) return ['users' => []];
  if (!isset($data['users']) || !is_array($data['users'])) $data['users'] = [];
  return $data;
}

function ds_save(array $data): void {
  $p = ds_path();
  $dir = dirname($p);
  if (!is_dir($dir)) @mkdir($dir, 0777, true);

  $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  if (!is_string($json)) return;

  $tmp = $p . '.tmp';
  file_put_contents($tmp, $json);
  @rename($tmp, $p);
}

function ds_now(): string { return date('c'); }

function ds_device_label(): string {
  $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
  $ua = preg_replace('/\s+/', ' ', trim($ua));
  if ($ua === '') $ua = 'Unknown device';
  if (function_exists('mb_strlen') && mb_strlen($ua, 'UTF-8') > 90) $ua = mb_substr($ua, 0, 90, 'UTF-8') . '…';
  if (!function_exists('mb_strlen') && strlen($ua) > 90) $ua = substr($ua, 0, 90) . '…';
  return $ua;
}

function ds_device_cookie_name(): string { return 'ppdr_device_id'; }

function ds_device_id(): string {
  $name = ds_device_cookie_name();
  $v = $_COOKIE[$name] ?? null;

  if (is_string($v)) {
    $v = trim($v);
    if ($v !== '' && preg_match('/^[a-f0-9]{32,64}$/', $v)) {
      return $v;
    }
  }

  $new = bin2hex(random_bytes(16));
  $cookieDomain = (string)(getenv('COOKIE_DOMAIN') ?: '');

  $isHttps =
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

  $opts = [
    'expires'  => time() + 365 * 86400,
    'path'     => '/',
    'secure'   => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
  ];
  if ($cookieDomain !== '') $opts['domain'] = $cookieDomain;

  setcookie($name, $new, $opts);
  $_COOKIE[$name] = $new;

  return $new;
}

/**
 * Call on LOGIN success.
 */
function ds_on_login(string $uid, string $sessionToken, int $maxDevices = 2): array {
  $data = ds_load();
  if (!isset($data['users'][$uid]) || !is_array($data['users'][$uid])) {
    $data['users'][$uid] = [
      'devices' => [],
      'active_session' => '',
      'active_device' => '',
      'active_at' => '',
    ];
  }

  $u = $data['users'][$uid];
  if (!isset($u['devices']) || !is_array($u['devices'])) $u['devices'] = [];

  $deviceId = ds_device_id();
  $label = ds_device_label();

  if (!isset($u['devices'][$deviceId])) {
    if (count($u['devices']) >= $maxDevices) {
      return ['ok' => false, 'error' => 'MAX_DEVICES', 'max' => $maxDevices];
    }
    $u['devices'][$deviceId] = [
      'label' => $label,
      'first_seen' => ds_now(),
      'last_seen' => ds_now(),
    ];
  } else {
    $u['devices'][$deviceId]['last_seen'] = ds_now();
    if (empty($u['devices'][$deviceId]['label'])) $u['devices'][$deviceId]['label'] = $label;
  }

  $u['active_session'] = $sessionToken;
  $u['active_device']  = $deviceId;
  $u['active_at']      = ds_now();

  $data['users'][$uid] = $u;
  ds_save($data);

  return ['ok' => true];
}

function ds_is_session_active(string $uid, string $sessionToken): bool {
  $data = ds_load();
  $u = $data['users'][$uid] ?? null;
  if (!is_array($u)) return true;

  $active = (string)($u['active_session'] ?? '');
  if ($active === '') return true;

  return hash_equals($active, $sessionToken);
}