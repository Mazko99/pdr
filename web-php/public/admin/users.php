<?php
declare(strict_types=1);

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../../src/chat_store.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
  @session_start();
}

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function users_json_path(): string {
  return __DIR__ . '/../../storage/users.json';
}

/**
 * Завжди намагаємось читати users.json як:
 * { "users": [ ... ] }
 * Якщо "users" нема — fallback на інші формати.
 *
 * Повертає LIST користувачів (масив масивів).
 */
function load_users_list(): array {
  $path = users_json_path();
  if (!is_file($path)) return [];

  $raw = file_get_contents($path);
  if ($raw === false) return [];

  $data = json_decode($raw, true);
  if (!is_array($data)) return [];

  // ✅ ГОЛОВНЕ: якщо є ключ "users" — беремо ТІЛЬКИ ЙОГО
  if (isset($data['users']) && is_array($data['users'])) {
    $out = [];
    foreach ($data['users'] as $u) {
      if (is_array($u)) $out[] = $u;
    }
    return $out;
  }

  // fallback: якщо файл це просто список
  $isList = array_keys($data) === range(0, count($data) - 1);
  if ($isList) {
    $out = [];
    foreach ($data as $u) {
      if (is_array($u)) $out[] = $u;
    }
    return $out;
  }

  // fallback: якщо файл це map id=>user
  $out = [];
  foreach ($data as $k => $u) {
    if (is_array($u)) $out[] = $u;
  }
  return $out;
}

function fmt(?string $iso): string {
  $iso = trim((string)$iso);
  if ($iso === '' || $iso === 'null') return '—';
  return $iso;
}

$users = load_users_list();

// search
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
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800;900&display=swap" rel="stylesheet">

  <style>
    :root{
      --bg:#f6f7f7;
      --card:#fff;
      --text:#0b1b14;
      --muted:rgba(11,27,20,.55);
      --line:rgba(11,27,20,.08);
      --green:#0a7a3d;
    }
    *{box-sizing:border-box}
    body{
      margin:0;
      font-family:Manrope,system-ui,-apple-system,Segoe UI,Roboto,Arial;
      background:var(--bg);
      color:var(--text);
    }
    .topbar{
      background:var(--card);
      border-bottom:1px solid var(--line);
      padding:14px 16px;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
    }
    .brand{
      font-weight:900;
      letter-spacing:.2px;
    }
    .actions{
      display:flex;
      gap:10px;
      align-items:center;
      flex-wrap:wrap;
    }
    .btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:8px;
      padding:10px 14px;
      border-radius:12px;
      border:1px solid rgba(11,27,20,.12);
      background:#fff;
      font-weight:900;
      cursor:pointer;
      text-decoration:none;
      color:inherit;
    }
    .btn--primary{
      background:var(--green);
      color:#fff;
      border-color:rgba(10,122,61,.25);
    }
    .badge{
      display:inline-flex;
      min-width:22px;
      height:22px;
      padding:0 8px;
      border-radius:999px;
      background:var(--green);
      color:#fff;
      align-items:center;
      justify-content:center;
      font-weight:900;
      font-size:12px;
    }
    .wrap{
      max-width:1200px;
      margin:0 auto;
      padding:16px;
    }
    .card{
      background:var(--card);
      border:1px solid var(--line);
      border-radius:16px;
      padding:14px;
      overflow:auto;
    }
    table{
      width:100%;
      border-collapse:collapse;
      min-width: 920px;
    }
    th, td{
      padding:14px 14px;
      border-bottom:1px solid rgba(11,27,20,.08);
      text-align:left;
      vertical-align:middle;
      font-weight:700;
      white-space:nowrap;
    }
    th{ font-weight:900; }
    .muted{ color:var(--muted); font-weight:800; }
    .pill{
      display:inline-flex;
      padding:6px 10px;
      border-radius:999px;
      border:1px solid rgba(11,27,20,.12);
      background:#fff;
      font-weight:900;
      font-size:12px;
    }
    .searchbar{
      display:flex;
      gap:10px;
      align-items:center;
    }
    .search{
      padding:10px 12px;
      border-radius:12px;
      border:1px solid rgba(11,27,20,.18);
      min-width:260px;
      font-weight:800;
    }
  </style>
</head>
<body>

<div class="topbar">
  <div class="brand">Адмінка — Користувачі</div>
  <div class="actions">
    <a class="btn btn--primary" href="/admin/chat.php">
      Чати
      <?php if ($unreadTotal > 0): ?><span class="badge"><?= (int)$unreadTotal ?></span><?php endif; ?>
    </a>

    <form class="searchbar" method="get">
      <input class="search" name="q" value="<?= h($q) ?>" placeholder="Пошук: id, email, ім’я">
      <button class="btn" type="submit">Знайти</button>
      <?php if ($q !== ''): ?><a class="btn" href="/admin/users.php">Скинути</a><?php endif; ?>
    </form>
  </div>
</div>

<div class="wrap">
  <div class="card">
    <table>
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
              <a class="btn" href="/admin/user.php?id=<?= urlencode((string)($u['id'] ?? '')) ?>">Профіль</a>
              <a class="btn" href="/admin/chat.php?uid=<?= urlencode((string)($u['id'] ?? '')) ?>">Чат</a>
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

</body>
</html>