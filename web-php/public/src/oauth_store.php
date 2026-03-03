<?php
declare(strict_types=1);

/**
 * OAuth links storage: storage/oauth_links.json
 * Format:
 * {
 *   "providers": {
 *     "google": {
 *        "<sub>": {"provider":"google","provider_user_id":"<sub>","user_id":"<uid>","email":"...","name":"...","linked_at":"..."}
 *     }
 *   }
 * }
 */

function oauth_path(): string {
  return dirname(__DIR__) . '/storage/oauth_links.json';
}

function oauth_read(): array {
  $p = oauth_path();
  if (!is_file($p)) return ['providers' => []];

  $raw = file_get_contents($p);
  if (!is_string($raw) || trim($raw) === '') return ['providers' => []];

  // BOM
  if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);

  $data = json_decode($raw, true);
  if (!is_array($data)) return ['providers' => []];

  if (!isset($data['providers']) || !is_array($data['providers'])) $data['providers'] = [];
  return $data;
}

function oauth_write(array $data): void {
  $p = oauth_path();
  $dir = dirname($p);
  if (!is_dir($dir)) @mkdir($dir, 0777, true);

  if (!isset($data['providers']) || !is_array($data['providers'])) $data['providers'] = [];

  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  if (!is_string($json)) return;

  $tmp = $p . '.tmp';
  $fp = fopen($tmp, 'wb');
  if (!$fp) return;

  if (!flock($fp, LOCK_EX)) { fclose($fp); return; }

  fwrite($fp, $json);
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);

  @rename($tmp, $p);
}

function oauth_now(): string {
  return gmdate('c');
}

/**
 * Find link by provider + provider_user_id (e.g. google sub)
 * Returns array|null
 */
function oauth_find(string $provider, string $providerUserId): ?array {
  $provider = strtolower(trim($provider));
  $providerUserId = trim($providerUserId);
  if ($provider === '' || $providerUserId === '') return null;

  $data = oauth_read();
  $prov = $data['providers'][$provider] ?? null;
  if (!is_array($prov)) return null;

  $row = $prov[$providerUserId] ?? null;
  return is_array($row) ? $row : null;
}

/**
 * Create/update link (provider_user_id -> user_id)
 */
function oauth_link(string $provider, string $providerUserId, string $userId, string $email = '', string $name = ''): void {
  $provider = strtolower(trim($provider));
  $providerUserId = trim($providerUserId);
  $userId = trim($userId);

  if ($provider === '' || $providerUserId === '' || $userId === '') return;

  $data = oauth_read();
  if (!isset($data['providers'][$provider]) || !is_array($data['providers'][$provider])) {
    $data['providers'][$provider] = [];
  }

  $data['providers'][$provider][$providerUserId] = [
    'provider' => $provider,
    'provider_user_id' => $providerUserId,
    'user_id' => $userId,
    'email' => $email,
    'name' => $name,
    'linked_at' => oauth_now(),
  ];

  oauth_write($data);
}