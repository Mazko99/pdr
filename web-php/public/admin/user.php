<?php
declare(strict_types=1);

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../../src/users_store.php';
require_once __DIR__ . '/../../src/chat_store.php';

if (session_status() !== PHP_SESSION_ACTIVE) @session_start();

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$uid = (string)($_GET['id'] ?? '');
if ($uid === '') {
  http_response_code(400);
  echo 'Missing id';
  exit;
}

$user = user_find_by_id($uid);
if (!is_array($user)) {
  http_response_code(404);
  echo 'User not found';
  exit;
}

$notice = '';
$error = '';

function admin_users_json_path(): string {
  return __DIR__ . '/../../storage/users.json';
}

function admin_users_load_raw(): array {
  $p = admin_users_json_path();
  if (!is_file($p)) return ['users' => []];
  $raw = (string)file_get_contents($p);
  if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);
  $data = json_decode($raw, true);
  if (!is_array($data)) return ['users' => []];
  if (!isset($data['users']) || !is_array($data['users'])) {
    // якщо це просто список
    $isList = array_keys($data) === range(0, count($data) - 1);
    if ($isList) return ['users' => $data];
    return ['users' => []];
  }
  return $data;
}

function admin_users_save_raw(array $data): void {
  if (!isset($data['users']) || !is_array($data['users'])) $data['users'] = [];
  $p = admin_users_json_path();
  $dir = dirname($p);
  if (!is_dir($dir)) @mkdir($dir, 0775, true);

  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  if (!is_string($json)) return;

  $tmp = $p . '.tmp';
  file_put_contents($tmp, $json);
  @rename($tmp, $p);
}

function admin_users_update_user(array $newUser): void {
  $data = admin_users_load_raw();
  $id = (string)($newUser['id'] ?? '');
  if ($id === '') return;

  $out = [];
  $found = false;
  foreach (($data['users'] ?? []) as $u) {
    if (!is_array($u)) continue;
    if ((string)($u['id'] ?? '') === $id) {
      $out[] = $newUser;
      $found = true;
    } else {
      $out[] = $u;
    }
  }
  if (!$found) $out[] = $newUser;

  $data['users'] = $out;
  admin_users_save_raw($data);
}

function admin_users_delete_user(string $id): void {
  $data = admin_users_load_raw();
  $out = [];
  foreach (($data['users'] ?? []) as $u) {
    if (!is_array($u)) continue;
    if ((string)($u['id'] ?? '') === $id) continue;
    $out[] = $u;
  }
  $data['users'] = $out;
  admin_users_save_raw($data);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  admin_csrf_verify($_POST['csrf'] ?? null);

  $action = (string)($_POST['action'] ?? '');

  if ($action === 'grant_plan') {
    $plan = strtolower(trim((string)($_POST['plan'] ?? 'free')));
    if (!in_array($plan, ['free','basic','personal','dev'], true)) $plan = 'free';

    $days = (int)($_POST['days'] ?? 0);
    $expiresAt = trim((string)($_POST['expires_at'] ?? ''));

    $user['plan'] = $plan;
    $user['paid_at'] = gmdate('c');

    if ($plan === 'free') {
      $user['expires_at'] = null;
    } else {
      if ($expiresAt !== '') {
        // дозволяємо YYYY-MM-DD або ISO
        $ts = strtotime($expiresAt);
        if ($ts === false) {
          $error = 'Невірна дата expires_at.';
        } else {
          $user['expires_at'] = gmdate('c', $ts);
        }
      } else {
        if ($days <= 0) $days = 30;
        $user['expires_at'] = gmdate('c', time() + $days * 86400);
      }
    }

    if ($error === '') {
      admin_users_update_user($user);
      $notice = 'Підписку оновлено.';
    }
  }

  if ($action === 'reset_sessions') {
    if (function_exists('sessions_revoke_all_for_user')) {
      sessions_revoke_all_for_user($uid, null);
      $notice = 'Сесії відкликано.';
    } else {
      $error = 'sessions_revoke_all_for_user() не знайдено (перевір users_store.php).';
    }
  }

  if ($action === 'revoke_one_session') {
    $sid = (string)($_POST['sid'] ?? '');
    if ($sid !== '' && function_exists('session_revoke_for_user')) {
      session_revoke_for_user($uid, $sid);
      $notice = 'Сесію відкликано.';
    } else {
      $error = 'Нема sid або session_revoke_for_user() не знайдено.';
    }
  }

  if ($action === 'delete_user') {
    admin_users_delete_user($uid);
    header('Location: /admin/users.php', true, 302);
    exit;
  }

  // refresh user
  $user = user_find_by_id($uid);
}

$sessions = [];
if (function_exists('sessions_list_for_user')) {
  $sessions = sessions_list_for_user($uid);
  if (!is_array($sessions)) $sessions = [];
}

?><!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Адмінка — Профіль</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800;900&family=Unbounded:wght@500;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/style.css?v=4" />
  <style>
    .row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-start}
    .col{flex:1 1 360px}
    .tbl{width:100%;border-collapse:collapse}
    .tbl th,.tbl td{padding:10px 10px;border-bottom:1px solid rgba(11,27,20,.08);text-align:left;vertical-align:top}
    .tbl th{font-weight:900}
    .muted{opacity:.7;font-weight:800}
    .danger{border-color:rgba(180,35,24,.35)!important}
  </style>
</head>
<body>

<main class="section section--soft" style="padding-top:24px;">
  <div class="container" style="max-width:1100px;">

    <div class="account-card" style="margin-bottom:12px;">
      <div style="display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap">
        <div>
          <div class="h2" style="margin:0;">Профіль користувача</div>
          <div class="lead" style="margin:6px 0 0;">ID: <b><?= h($uid) ?></b></div>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
          <a class="btn btn--ghost" href="/admin/users.php">← Назад</a>
          <a class="btn btn--ghost" href="/admin/chat.php?uid=<?= urlencode($uid) ?>">Чат</a>
        </div>
      </div>

      <?php if ($notice !== ''): ?>
        <div class="notice notice--ok" style="margin-top:12px;"><?= h($notice) ?></div>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <div class="notice notice--bad" style="margin-top:12px;"><?= h($error) ?></div>
      <?php endif; ?>
    </div>

    <div class="row">
      <div class="account-card col">
        <div class="h3" style="margin-top:0;">Дані</div>
        <div class="muted">Імʼя</div>
        <div style="font-weight:900;margin-bottom:10px;"><?= h((string)($user['name'] ?? '')) ?></div>

        <div class="muted">Email</div>
        <div style="font-weight:900;margin-bottom:10px;"><?= h((string)($user['email'] ?? '')) ?></div>

        <div class="muted">Plan</div>
        <div style="font-weight:900;margin-bottom:10px;"><?= h((string)($user['plan'] ?? 'free')) ?></div>

        <div class="muted">Expires</div>
        <div style="font-weight:900;"><?= h((string)($user['expires_at'] ?? '—')) ?></div>
      </div>

      <div class="account-card col">
        <div class="h3" style="margin-top:0;">Керування підпискою</div>

        <form method="post" action="/admin/user.php?id=<?= urlencode($uid) ?>" style="display:grid;gap:10px;margin:0">
          <input type="hidden" name="csrf" value="<?= h((string)($_SESSION['admin_csrf'] ?? '')) ?>">
          <input type="hidden" name="action" value="grant_plan">

          <label style="font-weight:900;">Plan</label>
          <select class="input" name="plan" style="padding:12px;border-radius:12px;border:1px solid rgba(0,0,0,.12);font-weight:800;">
            <?php $pl = (string)($user['plan'] ?? 'free'); ?>
            <option value="free" <?= $pl==='free'?'selected':''; ?>>free</option>
            <option value="basic" <?= $pl==='basic'?'selected':''; ?>>basic</option>
            <option value="personal" <?= $pl==='personal'?'selected':''; ?>>personal</option>
            <option value="dev" <?= $pl==='dev'?'selected':''; ?>>dev</option>
          </select>

          <div class="muted">Варіант 1: дні (якщо expires_at пустий)</div>
          <input class="input" type="number" name="days" placeholder="Напр. 30" min="0"
                 style="padding:12px;border-radius:12px;border:1px solid rgba(0,0,0,.12);font-weight:800;">

          <div class="muted">Варіант 2: конкретна дата (YYYY-MM-DD або ISO)</div>
          <input class="input" type="text" name="expires_at" placeholder="2026-03-31"
                 style="padding:12px;border-radius:12px;border:1px solid rgba(0,0,0,.12);font-weight:800;">

          <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:6px;">
            <button class="btn btn--primary" type="submit">Зберегти</button>
            <a class="btn btn--ghost" href="/admin/user.php?id=<?= urlencode($uid) ?>">Оновити</a>
          </div>
        </form>

        <div style="height:14px"></div>

        <form method="post" action="/admin/user.php?id=<?= urlencode($uid) ?>" style="margin:0;">
          <input type="hidden" name="csrf" value="<?= h((string)($_SESSION['admin_csrf'] ?? '')) ?>">
          <input type="hidden" name="action" value="reset_sessions">
          <button class="btn btn--ghost" type="submit">Скинути активні сесії</button>
        </form>

        <div style="height:14px"></div>

        <form method="post" action="/admin/user.php?id=<?= urlencode($uid) ?>" style="margin:0;"
              onsubmit="return confirm('Точно видалити користувача?');">
          <input type="hidden" name="csrf" value="<?= h((string)($_SESSION['admin_csrf'] ?? '')) ?>">
          <input type="hidden" name="action" value="delete_user">
          <button class="btn btn--ghost danger" type="submit">Видалити користувача</button>
        </form>
      </div>
    </div>

    <div class="account-card" style="margin-top:12px;">
      <div class="h3" style="margin-top:0;">Пристрої / Активні сесії</div>

      <?php if (empty($sessions)): ?>
        <div class="muted">Нема активних сесій (або sessions_list_for_user() не ведеться).</div>
      <?php else: ?>
        <table class="tbl">
          <thead>
            <tr>
              <th>SID</th>
              <th>IP</th>
              <th>User-Agent</th>
              <th>Created</th>
              <th>Last seen</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($sessions as $s): ?>
              <tr>
                <td style="font-weight:900;"><?= h((string)($s['sid'] ?? '')) ?></td>
                <td class="muted"><?= h((string)($s['ip'] ?? '')) ?></td>
                <td class="muted" style="max-width:520px;white-space:normal;"><?= h((string)($s['ua'] ?? '')) ?></td>
                <td class="muted"><?= h((string)($s['created_at'] ?? '')) ?></td>
                <td class="muted"><?= h((string)($s['last_seen'] ?? '')) ?></td>
                <td>
                  <form method="post" action="/admin/user.php?id=<?= urlencode($uid) ?>" style="margin:0;">
                    <input type="hidden" name="csrf" value="<?= h((string)($_SESSION['admin_csrf'] ?? '')) ?>">
                    <input type="hidden" name="action" value="revoke_one_session">
                    <input type="hidden" name="sid" value="<?= h((string)($s['sid'] ?? '')) ?>">
                    <button class="btn btn--ghost" type="submit">Відкликати</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

  </div>
</main>
</body>
</html>