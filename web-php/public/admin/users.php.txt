<?php
declare(strict_types=1);

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../../src/users_store.php';
require_once __DIR__ . '/../../src/chat_store.php';

if (session_status() !== PHP_SESSION_ACTIVE) @session_start();

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function users_json_path(): string {
  return __DIR__ . '/../../storage/users.json';
}

function load_users_list(): array {
  $path = users_json_path();
  if (!is_file($path)) return [];

  $raw = file_get_contents($path);
  if ($raw === false) return [];

  if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);

  $data = json_decode($raw, true);
  if (!is_array($data)) return [];

  if (isset($data['users']) && is_array($data['users'])) {
    $out = [];
    foreach ($data['users'] as $u) if (is_array($u)) $out[] = $u;
    return $out;
  }

  $isList = array_keys($data) === range(0, count($data) - 1);
  if ($isList) {
    $out = [];
    foreach ($data as $u) if (is_array($u)) $out[] = $u;
    return $out;
  }

  $out = [];
  foreach ($data as $u) if (is_array($u)) $out[] = $u;
  return $out;
}

function fmt(?string $iso): string {
  $iso = trim((string)$iso);
  if ($iso === '' || $iso === 'null') return '—';
  return $iso;
}

$users = load_users_list();

$q = trim((string)($_GET['q'] ?? ''));
if ($q !== '') {
  $qq = mb_strtolower($q);
  $users = array_values(array_filter($users, function($u) use ($qq){
    $id = mb_strtolower((string)($u['id'] ?? ''));
    $email = mb_strtolower((string)($u['email'] ?? ''));
    $name = mb_strtolower((string)($u['name'] ?? ''));
    return (strpos($id, $qq) !== false) || (strpos($email, $qq) !== false) || (strpos($name, $qq) !== false);
  }));
}

$unreadTotal = chat_admin_unread_total();

?><!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Адмінка — Користувачі</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800;900&family=Unbounded:wght@500;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/style.css?v=4" />

  <style>
    .admin-wrap{max-width:1200px;margin:0 auto;padding:16px;}
    .admin-top{display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap}
    .admin-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
    .admin-table{width:100%;border-collapse:collapse;min-width:920px}
    .admin-table th,.admin-table td{padding:12px 12px;border-bottom:1px solid rgba(11,27,20,.08);text-align:left;white-space:nowrap}
    .admin-table th{font-weight:900}
    .admin-card{overflow:auto}
    .admin-badge{display:inline-flex;min-width:22px;height:22px;padding:0 8px;border-radius:999px;background:#0a7a3d;color:#fff;align-items:center;justify-content:center;font-weight:900;font-size:12px}
    .admin-search{padding:10px 12px;border-radius:12px;border:1px solid rgba(11,27,20,.18);min-width:260px;font-weight:800}
    .pill{display:inline-flex;padding:6px 10px;border-radius:999px;border:1px solid rgba(11,27,20,.12);background:#fff;font-weight:900;font-size:12px}
    .muted{opacity:.7;font-weight:800}
  </style>
</head>
<body>

<main class="section section--soft" style="padding-top:24px;">
  <div class="container admin-wrap">
    <div class="account-card" style="margin-bottom:12px;">
      <div class="admin-top">
        <div>
          <div class="h2" style="margin:0;">Адмінка — Користувачі</div>
          <div class="lead" style="margin:6px 0 0;">Керування користувачами, підписками, чатами.</div>
        </div>

        <div class="admin-actions">
          <a class="btn btn--primary" href="/admin/chat.php">
            Чати
            <?php if ($unreadTotal > 0): ?><span class="admin-badge"><?= (int)$unreadTotal ?></span><?php endif; ?>
          </a>

          <form method="get" style="display:flex; gap:10px; align-items:center; margin:0;">
            <input class="admin-search" name="q" value="<?= h($q) ?>" placeholder="Пошук: id, email, ім’я">
            <button class="btn btn--ghost" type="submit">Знайти</button>
            <?php if ($q !== ''): ?><a class="btn btn--ghost" href="/admin/users.php">Скинути</a><?php endif; ?>
          </form>
        </div>
      </div>
    </div>

    <div class="account-card admin-card">
      <table class="admin-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Ім’я</th>
            <th>Email</th>
            <th>Plan</th>
            <th>Expires</th>
            <th>Created</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u): ?>
            <tr>
              <td><?= h((string)($u['id'] ?? '')) ?></td>
              <td><?= h((string)($u['name'] ?? '')) ?></td>
              <td><?= h((string)($u['email'] ?? '')) ?></td>
              <td><span class="pill"><?= h((string)($u['plan'] ?? 'free')) ?></span></td>
              <td class="muted"><?= h(fmt((string)($u['expires_at'] ?? ''))) ?></td>
              <td class="muted"><?= h(fmt((string)($u['created_at'] ?? ''))) ?></td>
              <td style="display:flex; gap:10px; align-items:center;">
                <a class="btn btn--ghost" href="/admin/user.php?id=<?= urlencode((string)($u['id'] ?? '')) ?>">Профіль</a>
                <a class="btn btn--ghost" href="/admin/chat.php?uid=<?= urlencode((string)($u['id'] ?? '')) ?>">Чат</a>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($users)): ?>
            <tr><td colspan="7" class="muted">Нічого не знайдено.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

  </div>
</main>

</body>
</html>