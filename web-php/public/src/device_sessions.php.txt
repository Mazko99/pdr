<?php
declare(strict_types=1);

/**
 * Device + Session policy:
 * - Max 2 remembered devices per user
 * - Only 1 active session at a time (single-login)
 *
 * Storage: /storage/device_sessions.json
 */

function ds_path(): string {
  return dirname(__DIR__) . '/storage/device_sessions.json';
}

function ds_load(): array {
  $p = ds_path();
  if (!is_file($p)) return ['users' => []];
  $raw = file_get_contents($p);
  if ($raw === false) return ['users' => []];
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

/**
 * Helpers: safe length/substr without mbstring dependency.
 */
function ds_strlen(string $s): int {
  // if mbstring is available, use it for better unicode handling
  if (function_exists('mb_strlen')) return (int)mb_strlen($s, 'UTF-8');
  return strlen($s);
}

function ds_substr(string $s, int $start, ?int $len = null): string {
  if (function_exists('mb_substr')) {
    return $len === null ? (string)mb_substr($s, $start, null, 'UTF-8') : (string)mb_substr($s, $start, $len, 'UTF-8');
  }
  return $len === null ? substr($s, $start) : substr($s, $start, $len);
}

/**
 * Stable device fingerprint.
 * Not perfect (browsers can change UA/IP), but OK for "max devices" policy.
 */
function ds_device_id(): string {
  $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
  $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
  $lang = (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
  $secch = (string)($_SERVER['HTTP_SEC_CH_UA'] ?? '');
  $seed = $ua . '|' . $lang . '|' . $secch . '|' . $ip;
  return hash('sha256', $seed);
}

function ds_device_label(): string {
  $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
  $ua = preg_replace('/\s+/', ' ', trim($ua));

  // limit label length safely
  if (ds_strlen($ua) > 90) $ua = ds_substr($ua, 0, 90) . '…';

  return $ua !== '' ? $ua : 'Unknown device';
}

/**
 * Call on LOGIN success.
 * Returns:
 * - ['ok'=>true] OR
 * - ['ok'=>false, 'error'=>'MAX_DEVICES', 'max'=>2]
 */
function ds_on_login(string $uid, string $sessionToken, int $maxDevices = 2): array {
  $data = ds_load();
  if (!isset($data['users'][$uid]) || !is_array($data['users'][$uid])) {
    $data['users'][$uid] = [
      'devices' => [],        // device_id => ['label'=>..., 'first_seen'=>..., 'last_seen'=>...]
      'active_session' => '', // session token
      'active_device' => '',  // device id
      'active_at' => '',
    ];
  }
  $u = $data['users'][$uid];

  if (!isset($u['devices']) || !is_array($u['devices'])) $u['devices'] = [];

  $deviceId = ds_device_id();
  $label = ds_device_label();

  // If device not known: enforce max devices
  if (!isset($u['devices'][$deviceId])) {
    $count = count($u['devices']);
    if ($count >= $maxDevices) {
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

  // ✅ Single active session: overwrite active session token
  $u['active_session'] = $sessionToken;
  $u['active_device']  = $deviceId;
  $u['active_at']      = ds_now();

  $data['users'][$uid] = $u;
  ds_save($data);

  return ['ok' => true];
}

/**
 * Call on each request after auth.
 * Returns true if session is still the active one, else false => force logout.
 */
function ds_is_session_active(string $uid, string $sessionToken): bool {
  $data = ds_load();
  $u = $data['users'][$uid] ?? null;
  if (!is_array($u)) return true; // if no policy data -> allow
  $active = (string)($u['active_session'] ?? '');
  if ($active === '') return true;
  return hash_equals($active, $sessionToken);
}

/**
 * Optional: call on logout
 */
function ds_on_logout(string $uid, string $sessionToken): void {
  $data = ds_load();
  $u = $data['users'][$uid] ?? null;
  if (!is_array($u)) return;
  if (isset($u['active_session']) && hash_equals((string)$u['active_session'], $sessionToken)) {
    $u['active_session'] = '';
    $u['active_device'] = '';
    $u['active_at'] = '';
    $data['users'][$uid] = $u;
    ds_save($data);
  }
}