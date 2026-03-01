<?php
declare(strict_types=1);

/**
 * storage/mono_payments.json
 * {
 *   "invoices": {
 *     "invId": {
 *       "invoice_id":"...",
 *       "user_id":"1",
 *       "kind":"trial_hold|plan_buy|trial_charge",
 *       "plan":"12|30",
 *       "amount": 69900,
 *       "status":"created|paid|failed",
 *       "created_at":"...",
 *       "paid_at":"...",
 *       "wallet_id":"...",   // if tokenization present
 *       "meta": { ... }
 *     }
 *   },
 *   "trials": {
 *     "user_id": {
 *       "user_id":"1",
 *       "plan":"30",
 *       "charge_amount":69900,
 *       "wallet_id":"...",
 *       "due_at": 1710000000,
 *       "status":"pending|charged|failed",
 *       "created_at":"..."
 *     }
 *   }
 * }
 */

function mono_store_path(): string {
  return dirname(__DIR__) . '/storage/mono_payments.json';
}

function mono_store_load(): array {
  $p = mono_store_path();
  if (!is_file($p)) return ['invoices'=>[], 'trials'=>[]];
  $raw = (string)file_get_contents($p);
  if (trim($raw) === '') return ['invoices'=>[], 'trials'=>[]];
  $data = json_decode($raw, true);
  if (!is_array($data)) $data = [];
  if (!isset($data['invoices']) || !is_array($data['invoices'])) $data['invoices'] = [];
  if (!isset($data['trials']) || !is_array($data['trials'])) $data['trials'] = [];
  return $data;
}

function mono_store_save(array $data): void {
  if (!isset($data['invoices']) || !is_array($data['invoices'])) $data['invoices'] = [];
  if (!isset($data['trials']) || !is_array($data['trials'])) $data['trials'] = [];

  $p = mono_store_path();
  $dir = dirname($p);
  if (!is_dir($dir)) @mkdir($dir, 0775, true);

  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  if ($json === false) throw new RuntimeException('mono_store_save: json_encode failed');

  $tmp = $p . '.tmp';
  $fp = fopen($tmp, 'wb');
  if (!$fp) throw new RuntimeException('mono_store_save: cannot open tmp');
  if (!flock($fp, LOCK_EX)) { fclose($fp); throw new RuntimeException('mono_store_save: cannot lock tmp'); }

  fwrite($fp, $json);
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);

  @rename($tmp, $p);
}

function mono_invoice_put(array $row): void {
  $invId = (string)($row['invoice_id'] ?? '');
  if ($invId === '') return;

  $data = mono_store_load();
  $data['invoices'][$invId] = $row;
  mono_store_save($data);
}

function mono_invoice_get(string $invoiceId): ?array {
  $data = mono_store_load();
  $r = $data['invoices'][$invoiceId] ?? null;
  return is_array($r) ? $r : null;
}

function mono_trial_set(array $row): void {
  $uid = (string)($row['user_id'] ?? '');
  if ($uid === '') return;

  $data = mono_store_load();
  $data['trials'][$uid] = $row;
  mono_store_save($data);
}

function mono_trial_get(string $userId): ?array {
  $data = mono_store_load();
  $r = $data['trials'][$userId] ?? null;
  return is_array($r) ? $r : null;
}

function mono_trials_due(int $now): array {
  $data = mono_store_load();
  $out = [];
  foreach (($data['trials'] ?? []) as $uid => $t) {
    if (!is_array($t)) continue;
    if ((string)($t['status'] ?? '') !== 'pending') continue;
    $due = (int)($t['due_at'] ?? 0);
    if ($due > 0 && $due <= $now) $out[] = $t;
  }
  return $out;
}