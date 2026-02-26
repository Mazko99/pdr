<?php
declare(strict_types=1);

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../../src/users_store.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
  @session_start();
}

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function csrf_token_admin(): string {
  if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(16));
  return (string)$_SESSION['admin_csrf'];
}

function csrf_verify_admin(?string $token): void {
  $ok = isset($_SESSION['admin_csrf']) && is_string($_SESSION['admin_csrf']) && hash_equals($_SESSION['admin_csrf'], (string)$token);
  if (!$ok) {
    http_response_code(419);
    echo "CSRF помилка.";
    exit;
  }
}

function admin_redirect(string $url): void {
  header('Location: ' . $url, true, 302);
  exit;
}

function admin_users_json_path(): string {
  return __DIR__ . '/../../storage/users.json';
}

/**
 * ЧИТАННЯ: підтримує { "users": [...] } і “биті” формати.
 * Повертає MAP: [id => userArray]
 */
function admin_load_users_fallback(): array {
  $path = admin_users_json_path();
  if (!is_file($path)) return [];

  $raw = file_get_contents($path);
  if ($raw === false) return [];

  $data = json_decode($raw, true);
  if (!is_array($data)) return [];

  // ✅ якщо є ключ users — це головний формат
  if (isset($data['users']) && is_array($data['users'])) {
    $out = [];
    foreach ($data['users'] as $u) {
      if (!is_array($u)) continue;
      $id = (string)($u['id'] ?? '');
      if ($id === '') continue;
      $out[$id] = $u;
    }
    return $out;
  }

  // fallback: list
  $isList = array_keys($data) === range(0, count($data) - 1);
  if ($isList) {
    $out = [];
    foreach ($data as $u) {
      if (!is_array($u)) continue;
      $id = (string)($u['id'] ?? '');
      if ($id === '') continue;
      $out[$id] = $u;
    }
    return $out;
  }

  // fallback: map
  $out = [];
  foreach ($data as $k => $u) {
    if (!is_array($u)) continue;
    $id = (string)($u['id'] ?? $k);
    if ($id === '') continue;
    $out[$id] = $u;
  }
  return $out;
}

/**
 * ЗАПИС: зберігаємо строго { "users": [ ... ] }
 */
function admin_save_users_strict(array $usersMap): void {
  $list = [];
  foreach ($usersMap as $id => $u) {
    if (!is_array($u)) continue;
    $u['id'] = (string)($u['id'] ?? $id);
    if ($u['id'] === '') continue;
    $list[] = $u;
  }

  $path = admin_users_json_path();
  $dir = dirname($path);
  if (!is_dir($dir)) @mkdir($dir, 0775, true);

  $json = json_encode(['users' => array_values($list)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  if ($json === false) {
    throw new RuntimeException('admin_save_users_strict: json_encode failed');
  }

  $tmp = $path . '.tmp';
  $fp = fopen($tmp, 'wb');
  if (!$fp) throw new RuntimeException('admin_save_users_strict: cannot open tmp');
  if (!flock($fp, LOCK_EX)) { fclose($fp); throw new RuntimeException('admin_save_users_strict: cannot lock tmp'); }

  fwrite($fp, $json);
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);

  @rename($tmp, $path);
}

$users = admin_load_users_fallback();

$id = (string)($_GET['id'] ?? '');
if ($id === '' || !isset($users[$id]) || !is_array($users[$id])) {
  http_response_code(404);
  echo "Користувача не знайдено.";
  exit;
}

$realKey = $id;

/* ==============
   POST ACTIONS
============== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify_admin((string)($_POST['csrf'] ?? ''));

  $action = (string)($_POST['action'] ?? '');

  if ($action === 'grant_plan') {
    $plan = (string)($_POST['plan'] ?? 'free');
    $days = (int)($_POST['days'] ?? 30);
    if ($days < 0) $days = 0;

    // allowed plans
    $allowed = ['free','dev','basic','personal'];
    if (!in_array($plan, $allowed, true)) $plan = 'free';

    $users[$realKey]['plan'] = $plan;
    $users[$realKey]['plan_set_at'] = gmdate('c');

    if ($plan === 'free') {
      $users[$realKey]['paid_at'] = null;
      $users[$realKey]['expires_at'] = null;
    } else {
      $users[$realKey]['paid_at'] = gmdate('c');
      if ($days > 0) {
        $users[$realKey]['expires_at'] = gmdate('c', time() + $days * 86400);
      } else {
        $users[$realKey]['expires_at'] = null;
      }
    }

    admin_save_users_strict($users);
    admin_redirect('/admin/user.php?id=' . urlencode($id) . '&ok=1');
  }

  // ✅ НОВЕ: скинути активні сесії користувача
  if ($action === 'reset_sessions') {
    sessions_revoke_all_for_user($id, null); // все
    admin_redirect('/admin/user.php?id=' . urlencode($id) . '&sessions_reset=1');
  }

  // інші дії (якщо були в тебе) — лишаються як є
}

/* ==============
   VIEW
============== */

$u = $users[$realKey];

?><!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Адмінка — User #<?= h($id) ?></title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial; margin:0; background:#f6f7f7; color:#0b1b14;}
    a{color:inherit; text-decoration:none;}
    .wrap{max-width:1100px; margin:0 auto; padding:16px;}
    .top{display:flex; gap:10px; align-items:center; justify-content:space-between; padding:16px; background:#fff; border-bottom:1px solid rgba(11,27,20,.08);}
    .btn{display:inline-flex; align-items:center; justify-content:center; padding:10px 14px; border-radius:12px; background:#0a7a3d; color:#fff; font-weight:800; border:0; cursor:pointer;}
    .btn--ghost{background:#fff; color:#0b1b14; border:1px solid rgba(11,27,20,.12);}
    .card{background:#fff; border-radius:14px; border:1px solid rgba(11,27,20,.08); padding:14px; margin-top:14px;}
    .row{display:grid; grid-template-columns: 220px 1fr; gap:10px; padding:6px 0; border-bottom:1px solid rgba(11,27,20,.06);}
    .row:last-child{border-bottom:0}
    .muted{opacity:.65; font-weight:700;}
    .pill{display:inline-flex; padding:6px 10px; border-radius:999px; border:1px solid rgba(11,27,20,.12); background:#fff; font-weight:900; font-size:12px;}
    .ok{background:rgba(10,122,61,.10); border-color:rgba(10,122,61,.25);}
  </style>
</head>
<body>

<div class="top">
  <div style="display:flex; gap:10px; align-items:center;">
    <a class="btn btn--ghost" href="/admin/users.php">← Користувачі</a>
    <a class="btn btn--ghost" href="/admin/chat.php?uid=<?= urlencode($id) ?>">Чат з користувачем</a>
    <div style="font-weight:900;">User #<?= h($id) ?></div>
  </div>
  <div class="muted"><?= h((string)($u['email'] ?? '')) ?></div>
</div>

<div class="wrap">

  <?php if (!empty($_GET['ok'])): ?>
    <div class="card"><span class="pill ok">✅ Збережено</span></div>
  <?php endif; ?>

  <?php if (!empty($_GET['sessions_reset'])): ?>
    <div class="card"><span class="pill ok">✅ Сесії користувача скинуті</span></div>
  <?php endif; ?>

  <div class="card">
    <div class="row"><div class="muted">Ім’я</div><div><b><?= h((string)($u['name'] ?? '')) ?></b></div></div>
    <div class="row"><div class="muted">Email</div><div><?= h((string)($u['email'] ?? '')) ?></div></div>
    <div class="row"><div class="muted">План</div><div><span class="pill"><?= h((string)($u['plan'] ?? 'free')) ?></span></div></div>
    <div class="row"><div class="muted">Paid at</div><div><?= h((string)($u['paid_at'] ?? '—')) ?></div></div>
    <div class="row"><div class="muted">Expires</div><div><?= h((string)($u['expires_at'] ?? '—')) ?></div></div>
    <div class="row"><div class="muted">Created</div><div><?= h((string)($u['created_at'] ?? '—')) ?></div></div>
  </div>

  <div class="card">
    <div style="font-weight:900; margin-bottom:10px;">Доступ/План</div>

    <form method="post" style="display:flex; gap:10px; flex-wrap:wrap; align-items:end;">
      <input type="hidden" name="csrf" value="<?= h(csrf_token_admin()) ?>">
      <input type="hidden" name="action" value="grant_plan">

      <label style="display:flex; flex-direction:column; gap:6px; font-weight:900;">
        План
        <select name="plan" style="padding:10px 12px; border-radius:12px; border:1px solid rgba(11,27,20,.18); font-weight:800;">
          <?php foreach (['free','basic','personal','dev'] as $p): ?>
            <option value="<?= h($p) ?>" <?= ((string)($u['plan'] ?? 'free')===$p)?'selected':''; ?>><?= h($p) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label style="display:flex; flex-direction:column; gap:6px; font-weight:900;">
        Днів
        <input type="number" name="days" value="30" min="0" style="padding:10px 12px; border-radius:12px; border:1px solid rgba(11,27,20,.18); font-weight:800; width:120px;">
      </label>

      <button class="btn" type="submit">Застосувати</button>
    </form>

    <div style="margin-top:12px;">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token_admin()) ?>">
        <input type="hidden" name="action" value="reset_sessions">
        <button class="btn btn--ghost" type="submit">Скинути активні сесії</button>
      </form>
      <div class="muted" style="margin-top:8px;">Після скидання користувача викине з усіх пристроїв (на наступному запиті).</div>
    </div>
  </div>

</div>
</body>
</html>