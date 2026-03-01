<?php
declare(strict_types=1);

/**
 * storage/chats.json
 * {
 *   "threads": {
 *     "USER_ID_OR_GUEST_ID": {
 *       "user_id":"1 or g_xxx",
 *       "updated_at":"...",
 *       "admin_unread": 0,
 *       "user_unread": 0,
 *       "meta": { "name":"", "email":"" },
 *       "messages":[
 *         {"id":1,"from":"user|admin","text":"...","ts":"..."}
 *       ]
 *     }
 *   },
 *   "last_id": 12
 * }
 */

function chats_store_path(): string {
  return dirname(__DIR__) . '/storage/chats.json'; // src/.. => project/storage/chats.json
}

function chats_load(): array {
  $p = chats_store_path();
  if (!is_file($p)) return ['threads' => [], 'last_id' => 0];

  $raw = (string)file_get_contents($p);
  if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);
  if (trim($raw) === '') return ['threads' => [], 'last_id' => 0];

  $data = json_decode($raw, true);
  if (!is_array($data)) return ['threads' => [], 'last_id' => 0];

  if (!isset($data['threads']) || !is_array($data['threads'])) $data['threads'] = [];
  if (!isset($data['last_id'])) $data['last_id'] = 0;
  $data['last_id'] = (int)$data['last_id'];

  return $data;
}

function chats_save(array $data): void {
  if (!isset($data['threads']) || !is_array($data['threads'])) $data['threads'] = [];
  if (!isset($data['last_id'])) $data['last_id'] = 0;
  $data['last_id'] = (int)$data['last_id'];

  $p = chats_store_path();
  $dir = dirname($p);
  if (!is_dir($dir)) @mkdir($dir, 0775, true);

  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  if ($json === false) throw new RuntimeException('chats_save: json_encode failed');

  $tmp = $p . '.tmp';
  $fp = fopen($tmp, 'wb');
  if (!$fp) throw new RuntimeException('chats_save: cannot open tmp');
  if (!flock($fp, LOCK_EX)) { fclose($fp); throw new RuntimeException('chats_save: cannot lock tmp'); }

  fwrite($fp, $json);
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);

  @rename($tmp, $p);
}

function chat_thread_get(string $userId): array {
  $userId = (string)$userId;
  $data = chats_load();
  $t = $data['threads'][$userId] ?? null;

  if (!is_array($t)) {
    $t = [
      'user_id' => $userId,
      'updated_at' => gmdate('c'),
      'admin_unread' => 0,
      'user_unread' => 0,
      'meta' => [],
      'messages' => [],
    ];
  }

  if (!isset($t['messages']) || !is_array($t['messages'])) $t['messages'] = [];
  if (!isset($t['admin_unread'])) $t['admin_unread'] = 0;
  if (!isset($t['user_unread'])) $t['user_unread'] = 0;
  if (!isset($t['meta']) || !is_array($t['meta'])) $t['meta'] = [];

  return $t;
}

/**
 * Оновлює meta (name/email) для треду, якщо прийшли непорожні значення
 */
function chat_thread_meta_merge(string $userId, array $meta): void {
  $userId = (string)$userId;
  $data = chats_load();
  $t = $data['threads'][$userId] ?? null;
  if (!is_array($t)) $t = chat_thread_get($userId);

  if (!isset($t['meta']) || !is_array($t['meta'])) $t['meta'] = [];

  $name = trim((string)($meta['name'] ?? ''));
  $email = trim((string)($meta['email'] ?? ''));

  if ($name !== '') $t['meta']['name'] = $name;
  if ($email !== '') $t['meta']['email'] = $email;

  $t['updated_at'] = gmdate('c');
  $data['threads'][$userId] = $t;
  chats_save($data);
}

function chat_message_add(string $userId, string $from, string $text, array $meta = []): array {
  $userId = (string)$userId;
  $from = $from === 'admin' ? 'admin' : 'user';
  $text = trim($text);
  if ($userId === '' || $text === '') return ['ok' => false];

  $data = chats_load();
  $thread = $data['threads'][$userId] ?? null;
  if (!is_array($thread)) $thread = chat_thread_get($userId);

  // meta
  if (!isset($thread['meta']) || !is_array($thread['meta'])) $thread['meta'] = [];
  $name = trim((string)($meta['name'] ?? ''));
  $email = trim((string)($meta['email'] ?? ''));
  if ($name !== '') $thread['meta']['name'] = $name;
  if ($email !== '') $thread['meta']['email'] = $email;

  $data['last_id'] = (int)$data['last_id'] + 1;
  $msg = [
    'id' => (int)$data['last_id'],
    'from' => $from,
    'text' => $text,
    'ts' => gmdate('c'),
  ];

  if (!isset($thread['messages']) || !is_array($thread['messages'])) $thread['messages'] = [];
  $thread['messages'][] = $msg;
  $thread['updated_at'] = gmdate('c');

  if ($from === 'user') $thread['admin_unread'] = (int)($thread['admin_unread'] ?? 0) + 1;
  else $thread['user_unread'] = (int)($thread['user_unread'] ?? 0) + 1;

  $data['threads'][$userId] = $thread;
  chats_save($data);

  return ['ok' => true, 'message' => $msg];
}

function chat_messages_since(string $userId, int $afterId): array {
  $t = chat_thread_get($userId);
  $afterId = (int)$afterId;
  $out = [];

  foreach (($t['messages'] ?? []) as $m) {
    if (!is_array($m)) continue;
    $id = (int)($m['id'] ?? 0);
    if ($id > $afterId) $out[] = $m;
  }
  return $out;
}

function chat_mark_read_for(string $userId, string $who): void {
  $userId = (string)$userId;
  $who = $who === 'admin' ? 'admin' : 'user';

  $data = chats_load();
  $t = $data['threads'][$userId] ?? null;
  if (!is_array($t)) return;

  if ($who === 'admin') $t['admin_unread'] = 0;
  else $t['user_unread'] = 0;

  $data['threads'][$userId] = $t;
  chats_save($data);
}

function chat_threads_list(): array {
  $data = chats_load();
  $threads = $data['threads'] ?? [];
  if (!is_array($threads)) return [];

  $out = [];
  foreach ($threads as $uid => $t) {
    if (!is_array($t)) continue;
    $t['user_id'] = (string)($t['user_id'] ?? $uid);
    if (!isset($t['meta']) || !is_array($t['meta'])) $t['meta'] = [];
    $out[] = $t;
  }

  usort($out, function($a, $b){
    return strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? ''));
  });

  return $out;
}

function chat_admin_unread_total(): int {
  $sum = 0;
  foreach (chat_threads_list() as $t) $sum += (int)($t['admin_unread'] ?? 0);
  return $sum;
}