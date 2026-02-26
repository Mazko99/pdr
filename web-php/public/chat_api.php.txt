<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
  @session_start();
}

/**
 * Универсальный API для чата (працює з будь-якої сторінки)
 *
 * GET  /chat_api.php?action=fetch
 * POST /chat_api.php?action=send  (JSON: {text:"..."})
 *
 * ADMIN:
 * GET  /chat_api.php?action=list
 * GET  /chat_api.php?action=fetch&thread=u_123
 * POST /chat_api.php?action=send&thread=u_123
 * POST /chat_api.php?action=mark_read&thread=u_123&who=admin|user
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$isAdmin = !empty($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
$uid = $_SESSION['user_id'] ?? null;

// ✅ ЧАТ ТІЛЬКИ ДЛЯ ЗАРЕЄСТРОВАНИХ (або адмін)
if (!$isAdmin && !$uid) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
  exit;
}

function safe_thread(string $thread): string {
  $thread = preg_replace('/[^a-zA-Z0-9_\-]/', '', $thread) ?? '';
  return $thread !== '' ? $thread : 'invalid';
}

function storage_base_dir(): string {
  // Підбираємо базову папку storage, щоб працювало і в public, і в інших директоріях
  $candidates = [
    __DIR__ . '/../storage',
    __DIR__ . '/storage',
    dirname(__DIR__) . '/storage',
  ];
  foreach ($candidates as $p) {
    if (is_dir($p)) return $p;
  }
  // якщо немає — створюємо ../storage відносно public
  $p = __DIR__ . '/../storage';
  @mkdir($p, 0775, true);
  return $p;
}

function ensure_dir(string $path): void {
  if (!is_dir($path)) @mkdir($path, 0775, true);
}

function json_read(string $path): array {
  if (!is_file($path)) return [];
  $raw = file_get_contents($path);
  if ($raw === false || $raw === '') return [];
  if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function json_write(string $path, array $data): bool {
  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  if (!is_string($json)) return false;
  $tmp = $path . '.tmp';
  $ok = file_put_contents($tmp, $json, LOCK_EX);
  if ($ok === false) return false;
  return @rename($tmp, $path);
}

$action = (string)($_GET['action'] ?? '');
if ($action === '') {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Missing action'], JSON_UNESCAPED_UNICODE);
  exit;
}

// thread користувача
$defaultThread = $uid ? ('u_' . (string)$uid) : 'admin';
$threadId = $defaultThread;

// admin може працювати з будь-яким thread
if ($isAdmin && isset($_GET['thread'])) {
  $threadId = (string)$_GET['thread'];
}
$threadId = safe_thread($threadId);

$storage = storage_base_dir();
$threadsDir = $storage . '/chat_threads';
ensure_dir($threadsDir);

$threadFile = $threadsDir . '/' . $threadId . '.json';
$now = time();

function read_thread(string $file): array {
  $d = json_read($file);
  if (!isset($d['messages']) || !is_array($d['messages'])) $d['messages'] = [];
  if (!isset($d['meta']) || !is_array($d['meta'])) $d['meta'] = [];
  return $d;
}

function write_thread(string $file, array $d): bool {
  return json_write($file, $d);
}

function thread_label_from_meta(array $meta, string $thread): string {
  $name = (string)($meta['user_name'] ?? '');
  $email = (string)($meta['user_email'] ?? '');
  $uid = (string)($meta['user_id'] ?? '');
  $base = $name !== '' ? $name : ($email !== '' ? $email : $thread);
  if ($uid !== '') $base .= " (#{$uid})";
  return $base;
}

function list_threads(string $dir): array {
  $files = glob($dir . '/*.json') ?: [];
  $out = [];
  foreach ($files as $f) {
    $thread = basename($f, '.json');
    $data = json_read($f);
    $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
    $mtime = @filemtime($f);
    $out[] = [
      'thread' => $thread,
      'label' => thread_label_from_meta($meta, $thread),
      'updated_at' => is_int($mtime) ? $mtime : 0,
      'unread_admin' => !empty($meta['unread_admin']),
      'unread_user' => !empty($meta['unread_user']),
      'user_id' => (string)($meta['user_id'] ?? ''),
      'user_name' => (string)($meta['user_name'] ?? ''),
      'user_email' => (string)($meta['user_email'] ?? ''),
      'last_text' => (string)($meta['last_text'] ?? ''),
    ];
  }
  usort($out, fn($a, $b) => ($b['updated_at'] ?? 0) <=> ($a['updated_at'] ?? 0));
  return $out;
}

// ✅ ADMIN: список діалогів
if ($action === 'list') {
  if (!$isAdmin) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  echo json_encode(['ok' => true, 'threads' => list_threads($threadsDir)], JSON_UNESCAPED_UNICODE);
  exit;
}

// ✅ fetch
if ($action === 'fetch') {
  // користувач не може читати чужі thread
  if (!$isAdmin) {
    $own = safe_thread($defaultThread);
    if ($threadId !== $own) {
      http_response_code(403);
      echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
      exit;
    }
  }

  $data = read_thread($threadFile);

  // якщо ще немає мети — проставляємо
  if (empty($data['meta']['created_at'])) $data['meta']['created_at'] = $now;
  $data['meta']['updated_at'] = $now;

  // авто-мета по користувачу (для списку в адмінці)
  if (!$isAdmin && $uid) {
    $data['meta']['user_id'] = (string)$uid;
    $data['meta']['user_name'] = (string)($_SESSION['user_name'] ?? 'Користувач');
    $data['meta']['user_email'] = (string)($_SESSION['user_email'] ?? '');
  }

  // зберігаємо meta
  write_thread($threadFile, $data);

  echo json_encode([
    'ok' => true,
    'thread' => $threadId,
    'is_admin' => $isAdmin,
    'messages' => $data['messages'],
    'meta' => $data['meta'],
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

// ✅ mark_read
if ($action === 'mark_read') {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $who = (string)($_GET['who'] ?? '');
  if ($who !== 'admin' && $who !== 'user') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Bad who'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // користувач не може mark_read чужий
  if (!$isAdmin) {
    $own = safe_thread($defaultThread);
    if ($threadId !== $own) {
      http_response_code(403);
      echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $who = 'user';
  }

  $data = read_thread($threadFile);
  if (!isset($data['meta'])) $data['meta'] = [];

  if ($who === 'admin') $data['meta']['unread_admin'] = false;
  if ($who === 'user')  $data['meta']['unread_user']  = false;
  $data['meta']['updated_at'] = $now;

  write_thread($threadFile, $data);

  echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
  exit;
}

// ✅ send
if ($action === 'send') {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // користувач не може слати в чужий thread
  if (!$isAdmin) {
    $own = safe_thread($defaultThread);
    if ($threadId !== $own) {
      http_response_code(403);
      echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
      exit;
    }
  }

  $raw = file_get_contents('php://input');
  $payload = [];
  if (is_string($raw) && $raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $payload = $decoded;
  }
  if (empty($payload)) $payload = $_POST;

  $text = trim((string)($payload['text'] ?? ''));
  if ($text === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Empty message'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  if (mb_strlen($text, 'UTF-8') > 2000) {
    $text = mb_substr($text, 0, 2000, 'UTF-8');
  }

  $data = read_thread($threadFile);

  if (!isset($data['meta'])) $data['meta'] = [];
  if (empty($data['meta']['created_at'])) $data['meta']['created_at'] = $now;
  $data['meta']['updated_at'] = $now;

  $role = $isAdmin ? 'admin' : 'user';

  // meta по користувачу (щоб в адмінці було видно хто)
  if (!$isAdmin && $uid) {
    $data['meta']['user_id'] = (string)$uid;
    $data['meta']['user_name'] = (string)($_SESSION['user_name'] ?? 'Користувач');
    $data['meta']['user_email'] = (string)($_SESSION['user_email'] ?? '');
  }

  // unread логіка
  if ($role === 'user') {
    $data['meta']['unread_admin'] = true;
    $data['meta']['unread_user'] = false;
  } else {
    $data['meta']['unread_user'] = true;
    $data['meta']['unread_admin'] = false;
  }

  $msg = [
    'id' => bin2hex(random_bytes(8)),
    'ts' => $now,
    'role' => $role,
    'name' => $isAdmin ? 'Адмін' : (string)($_SESSION['user_name'] ?? 'Користувач'),
    'email' => $isAdmin ? '' : (string)($_SESSION['user_email'] ?? ''),
    'text' => $text,
  ];

  $data['messages'][] = $msg;

  // cap
  if (count($data['messages']) > 400) {
    $data['messages'] = array_slice($data['messages'], -400);
  }

  $data['meta']['last_text'] = $text;

  if (!write_thread($threadFile, $data)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to save'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  echo json_encode(['ok' => true, 'thread' => $threadId, 'message' => $msg], JSON_UNESCAPED_UNICODE);
  exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action'], JSON_UNESCAPED_UNICODE);
exit;