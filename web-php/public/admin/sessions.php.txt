<?php
declare(strict_types=1);

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../../src/users_store.php';

if (session_status() !== PHP_SESSION_ACTIVE) @session_start();

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function csrf_token_admin(): string {
  if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(16));
  return (string)$_SESSION['admin_csrf'];
}
function csrf_verify_admin(?string $token): void {
  $ok = isset($_SESSION['admin_csrf']) && is_string($_SESSION['admin_csrf']) && hash_equals($_SESSION['admin_csrf'], (string)$token);
  if (!$ok) { http_response_code(419); echo "CSRF помилка."; exit; }
}
function admin_redirect(string $url): void { header('Location: ' . $url, true, 302); exit; }

// ---- Safe wrappers (щоб не падало якщо функцій нема)
function sessions_supported(): bool {
  return function_exists('sessions_list_for_user')
      && function_exists('sessions_revoke')
      && function_exists('sessions_revoke_all_for_user');
}

$uid = trim((string)($_GET['uid'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify_admin((string)($_POST['csrf'] ?? ''));
  $action = (string)($_POST['action'] ?? '');

  if (!sessions_supported()) {
    admin_redirect('/admin/sessions.php?err=nosessions');
  }

  if ($action === 'revoke_one') {
    $sid = (string)($_POST['sid'] ?? '');
    if ($sid !== '') sessions_revoke($sid);
    admin_redirect('/admin/sessions.php?uid=' . urlencode($uid) . '&ok=1');
  }

  if ($action === 'revoke_all') {
    $u = (string)($_POST['uid'] ?? '');
    if ($u !== '') sessions_revoke_all_for_user($u, null);
    admin_redirect('/admin/sessions.php?uid=' . urlencode($u) . '&ok=1');
  }
}

$users = [];
// беремо список користувачів (щоб показати ім’я/емейл)
if (function_exists('user_all')) {
  $users = user_all();
} else {
  // fallback: якщо нема user_all, то мінімально
  $users = [];
}

$userMap = [];
if (is_array($users)) {
  foreach ($users as $u) {
    if (!is_array($u)) continue;
    $id = (string)($u['id'] ?? '');
    if ($id !== '') $userMap[$id] = $u;
  }
}

// sessions list
$rows = [];
if (sessions_supported() && $uid !== '') {
  $rows = sessions_list_for_user($uid);
  if (!is_array($rows)) $rows = [];
}

?><!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Адмінка — Сесії</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial; margin:0; background:#f6f7f7; color:#0b1b14;}
    a{color:inherit; text-decoration:none;}
    .top{display:flex; align-items:center; justify-content:space-between; gap:10px; padding:16px; background:#fff; border-bottom:1px solid rgba(11,27,20,.08);}
    .btn{display:inline-flex; align-items:center; justify-content:center; padding:10px 14px; border-radius:12px; background:#0a7a3d; color:#fff; font-weight:900; border:0; cursor:pointer;}
    .btn--ghost{background:#fff; color:#0b1b14; border:1px solid rgba(11,27,20,.12);}
    .wrap{max-width:1100px; margin:0 auto; padding:16px;}
    .card{background:#fff; border-radius:14px; border:1px solid rgba(11,27,20,.08); padding:14px; margin-top:14px; overflow:auto;}
    .muted{opacity:.65; font-weight:700;}
    table{width:100%; border-collapse:collapse; min-width: 900px;}
    th,td{padding:12px 12px; border-bottom:1px solid rgba(11,27,20,.08); text-align:left; vertical-align:middle;}
    th{font-weight:900;}
    .ok{padding:10px 12px; border-radius:12px; background:rgba(10,122,61,.10); border:1px solid rgba(10,122,61,.22); font-weight:900;}
    .err{padding:10px 12px; border-radius:12px; background:rgba(255,70,70,.08); border:1px solid rgba(255,70,70,.22); font-weight:900;}
    .input{padding:10px 12px; border-radius:12px; border:1px solid rgba(11,27,20,.18); font-weight:800; min-width:280px;}
    .row{display:flex; gap:10px; flex-wrap:wrap; align-items:center;}
  </style>
</head>
<body>

<div class="top">
  <div style="display:flex; gap:10px; align-items:center;">
    <a class="btn btn--ghost" href="/admin/users.php">← Користувачі</a>
    <div style="font-weight:900;">Сесії користувачів</div>
  </div>
  <a class="btn btn--ghost" href="/admin/chat.php">Чати</a>
</div>

<div class="wrap">

  <?php if (!empty($_GET['ok'])): ?><div class="card"><div class="ok">✅ Готово</div></div><?php endif; ?>
  <?php if (!empty($_GET['err'])): ?><div class="card"><div class="err">❌ Помилка: <?= h((string)$_GET['err']) ?></div></div><?php endif; ?>

  <div class="card">
    <div class="row">
      <form method="get" class="row">
        <input class="input" name="uid" value="<?= h($uid) ?>" placeholder="Введи user_id щоб подивитись сесії">
        <button class="btn" type="submit">Показати</button>
      </form>

      <?php if ($uid !== '' && sessions_supported()): ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h(csrf_token_admin()) ?>">
          <input type="hidden" name="action" value="revoke_all">
          <input type="hidden" name="uid" value="<?= h($uid) ?>">
          <button class="btn btn--ghost" type="submit">Вийти з усіх пристроїв (user)</button>
        </form>
      <?php endif; ?>
    </div>

    <div class="muted" style="margin-top:10px;">
      <?php if (!sessions_supported()): ?>
        У тебе поки не підключені функції sessions_* у bootstrap/users_store. (Але в “Безпека” вони вже працюють — значить тут буде ок після підключення тих самих функцій.)
      <?php else: ?>
        Показує активні сесії для користувача.
      <?php endif; ?>
    </div>
  </div>

  <?php if ($uid !== '' && sessions_supported()): ?>
    <div class="card">
      <div style="font-weight:900; margin-bottom:10px;">
        Сесії: <?= h($uid) ?>
        <?php
          $uu = $userMap[$uid] ?? null;
          if (is_array($uu)) {
            echo ' • ' . h((string)($uu['email'] ?? '')) . ' • ' . h((string)($uu['name'] ?? ''));
          }
        ?>
      </div>

      <table>
        <thead>
          <tr>
            <th>Session ID</th>
            <th>Пристрій/IP</th>
            <th>Остання активність</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $s): if (!is_array($s)) continue; ?>
            <tr>
              <td><?= h((string)($s['id'] ?? $s['sid'] ?? '')) ?></td>
              <td class="muted"><?= h((string)($s['ua'] ?? $s['user_agent'] ?? '')) ?> <?= h((string)($s['ip'] ?? '')) ?></td>
              <td class="muted"><?= h((string)($s['last_seen'] ?? $s['updated_at'] ?? '')) ?></td>
              <td>
                <form method="post">
                  <input type="hidden" name="csrf" value="<?= h(csrf_token_admin()) ?>">
                  <input type="hidden" name="action" value="revoke_one">
                  <input type="hidden" name="sid" value="<?= h((string)($s['id'] ?? $s['sid'] ?? '')) ?>">
                  <button class="btn btn--ghost" type="submit">Завершити</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($rows)): ?>
            <tr><td colspan="4" class="muted">Нема активних сесій для цього user_id.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

</div>
</body>
</html>