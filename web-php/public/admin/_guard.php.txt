<?php
declare(strict_types=1);

$bootstrap = __DIR__ . '/../../src/bootstrap.php';
$usersStore = __DIR__ . '/../../src/users_store.php';

if (is_file($bootstrap)) require_once $bootstrap;
if (is_file($usersStore)) require_once $usersStore;

if (session_status() !== PHP_SESSION_ACTIVE) {
  @session_start();
}

function admin_env_str(string $key, string $default = ''): string {
  $v = getenv($key);
  if ($v === false || $v === null || $v === '') return $default;
  return (string)$v;
}

function admin_require(): void {
  if (empty($_SESSION['is_admin'])) {
    header('Location: /admin/login.php', true, 302);
    exit;
  }
}

function admin_h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * --- USERS STORAGE LAYER (admin wrapper) ---
 * Працює через users_store.php якщо є функції,
 * або через JSON-файл storage/users.json (fallback).
 *
 * ВАЖЛИВО: всі назви з префіксом admin_ щоб не конфліктувати з твоїм src/users_store.php
 */

function admin_users_storage_path(): string {
  // fallback шлях (якщо в users_store.php нема своїх методів)
  return __DIR__ . '/../../storage/users.json';
}

function admin_users_load_all(): array {
  // якщо в users_store.php є users_all() — юзаємо
  if (function_exists('users_all')) {
    $all = users_all();
    return is_array($all) ? $all : [];
  }

  $path = admin_users_storage_path();
  if (!is_file($path)) return [];
  $raw = file_get_contents($path);
  $data = json_decode((string)$raw, true);
  return is_array($data) ? $data : [];
}

function admin_users_save_all(array $users): void {
  // якщо в users_store.php є users_save_all() — юзаємо
  if (function_exists('users_save_all')) {
    users_save_all($users);
    return;
  }

  $path = admin_users_storage_path();
  $dir = dirname($path);
  if (!is_dir($dir)) @mkdir($dir, 0777, true);
  file_put_contents($path, json_encode($users, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function admin_user_find(string $id): ?array {
  if (function_exists('user_find_by_id')) {
    $u = user_find_by_id($id);
    return is_array($u) ? $u : null;
  }

  $all = admin_users_load_all();
  foreach ($all as $u) {
    if ((string)($u['id'] ?? '') === (string)$id) return $u;
  }
  return null;
}

function admin_user_update(string $id, array $patch): bool {
  // якщо в users_store.php є user_update() — юзаємо
  if (function_exists('user_update')) {
    return (bool)user_update($id, $patch);
  }

  $all = admin_users_load_all();
  $found = false;

  foreach ($all as &$u) {
    if ((string)($u['id'] ?? '') === (string)$id) {
      $u = array_merge($u, $patch);
      $found = true;
      break;
    }
  }
  unset($u);

  if (!$found) return false;
  admin_users_save_all($all);
  return true;
}

function admin_user_delete(string $id): bool {
  // якщо в users_store.php є user_delete() — юзаємо
  if (function_exists('user_delete')) {
    return (bool)user_delete($id);
  }

  $all = admin_users_load_all();
  $before = count($all);
  $all = array_values(array_filter($all, fn($u) => (string)($u['id'] ?? '') !== (string)$id));
  if (count($all) === $before) return false;

  admin_users_save_all($all);
  return true;
}

function admin_fmt_date(?string $s): string {
  $s = trim((string)$s);
  if ($s === '') return '—';

  if (ctype_digit($s)) {
    return date('Y-m-d H:i', (int)$s);
  }
  return $s;
}

function admin_user_registered_at(array $u): string {
  return (string)($u['created_at'] ?? $u['registered_at'] ?? $u['reg_at'] ?? '—');
}

function admin_user_paid_at(array $u): string {
  return (string)($u['paid_at'] ?? $u['payment_date'] ?? $u['last_payment_at'] ?? '—');
}

function admin_user_expires_at(array $u): string {
  return (string)($u['subscription_until'] ?? $u['expires_at'] ?? $u['plan_until'] ?? '—');
}
<!-- ADMIN CHAT FLOAT BUTTON -->
<a href="/admin/chat.php" id="adminChatBtn" style="
  position: fixed;
  right: 18px;
  bottom: 18px;
  z-index: 99999;
  width: 56px;
  height: 56px;
  border-radius: 999px;
  background: #111;
  color: #fff;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  text-decoration: none;
  box-shadow: 0 16px 40px rgba(0,0,0,.22);
  font-size: 20px;
">
  💬
  <span id="adminChatBadge" style="
    display:none;
    position:absolute;
    top:-6px;
    right:-6px;
    width:22px;
    height:22px;
    border-radius:999px;
    background:#1FA34A;
    color:#fff;
    font-weight:900;
    font-size:12px;
    display:flex;
    align-items:center;
    justify-content:center;
    border:2px solid #fff;
  ">!</span>
</a>

<script>
(function(){
  async function api(url){
    const res = await fetch(url, {credentials:'same-origin'});
    return res.json();
  }
  async function tick(){
    try{
      const data = await api('/chat_api.php?action=list');
      if (!data || !data.ok) return;
      const threads = Array.isArray(data.threads) ? data.threads : [];
      const hasUnread = threads.some(t => t.unread_admin);
      const badge = document.getElementById('adminChatBadge');
      if (!badge) return;
      badge.style.display = hasUnread ? 'flex' : 'none';
    }catch(e){}
  }
  setInterval(tick, 2500);
  tick();
})();
</script>