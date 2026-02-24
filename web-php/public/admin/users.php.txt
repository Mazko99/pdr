<?php
declare(strict_types=1);

require_once __DIR__ . '/_guard.php';

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
  return $iso !== '' ? $iso : '—';
}

$q = trim((string)($_GET['q'] ?? ''));

$users = load_users_list();

// пошук
$filtered = [];
if ($q === '') {
  $filtered = $users;
} else {
  $qq = strtolower($q);
  foreach ($users as $u) {
    $id    = (string)($u['id'] ?? '');
    $name  = (string)($u['name'] ?? '');
    $email = (string)($u['email'] ?? '');

    $hay = strtolower($id . ' ' . $name . ' ' . $email);
    if (strpos($hay, $qq) !== false) {
      $filtered[] = $u;
    }
  }
}

$total = count($filtered);

// сортуємо по created_at (старіші вгору), якщо поля немає — як є
usort($filtered, function($a, $b) {
  $da = (string)($a['created_at'] ?? '');
  $db = (string)($b['created_at'] ?? '');
  return strcmp($da, $db);
});

?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Адмінка — Користувачі</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Unbounded:wght@500;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/style.css?v=6">

  <style>
    html, body { margin:0; padding:0; }
    .admin-header{
      padding:34px 0 22px;
      background:linear-gradient(180deg,#eaf3ef 0,#ffffff 100%);
      position:relative;
    }
    .admin-actions{
      position:absolute; right:20px; top:20px;
      display:flex; gap:10px; flex-wrap:wrap;
    }
    .table-wrap{ overflow:auto; }
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
    .muted{ color:rgba(11,27,20,.55); font-weight:800; }
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
      display:flex; gap:10px; align-items:center; flex-wrap:wrap;
      margin-top:14px;
    }
    .searchbar input{
      flex:1;
      min-width: 280px;
      padding:14px 16px;
      border-radius:18px;
      border:1px solid rgba(11,27,20,.12);
      outline:none;
      font-weight:800;
      background:#fff;
    }
    .actions-col{ min-width:140px; }
  </style>
</head>
<body>

<div class="admin-header">
  <div class="container">
    <div class="admin-actions">
      <a class="btn btn--ghost" href="/">На сайт</a>
      <a class="btn btn--ghost" href="/admin/logout.php">Вийти</a>
    </div>

    <h1 class="h1" style="margin:0;">Адмінка</h1>
    <p class="lead" style="margin:6px 0 0 0;">Користувачі: <b><?php echo (int)$total; ?></b></p>

    <form class="searchbar" method="get" action="/admin/users.php">
      <input type="text" understanding="off" name="q" value="<?php echo h($q); ?>" placeholder="Пошук по email / імені / id" />
      <button class="btn btn--primary" type="submit">Знайти</button>
      <a class="btn btn--ghost" href="/admin/users.php">Скинути</a>
    </form>
  </div>
</div>

<div class="container" style="margin-top:18px; margin-bottom:26px;">
  <div class="account-card">
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Користувач</th>
            <th>Email</th>
            <th>План</th>
            <th>Оплата (дата)</th>
            <th>Діє до</th>
            <th>Реєстрація</th>
            <th class="actions-col">Дії</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($filtered)): ?>
            <tr>
              <td colspan="8" class="muted">Нічого не знайдено.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($filtered as $u):
              $id    = (string)($u['id'] ?? '');
              $name  = (string)($u['name'] ?? '—');
              $email = (string)($u['email'] ?? '—');

              $plan = $u['plan'] ?? 'free';
              if ($plan === null || $plan === '') $plan = 'free';

              $paidAt    = (string)($u['paid_at'] ?? $u['payment_at'] ?? '');
              $expiresAt = (string)($u['expires_at'] ?? $u['subscription_until'] ?? '');
              $createdAt = (string)($u['created_at'] ?? $u['registered_at'] ?? '');

              $short = (strlen($id) > 12) ? substr($id, 0, 12) . '…' : $id;
            ?>
              <tr>
                <td><span class="pill" title="<?php echo h($id); ?>"><?php echo h($short !== '' ? $short : '—'); ?></span></td>
                <td><?php echo h($name); ?></td>
                <td><?php echo h($email); ?></td>
                <td><?php echo h((string)$plan); ?></td>
                <td><?php echo h(fmt($paidAt)); ?></td>
                <td><?php echo h(fmt($expiresAt)); ?></td>
                <td><?php echo h(fmt($createdAt)); ?></td>
                <td class="actions-col">
                  <!-- ✅ ВАЖЛИВО: передаємо саме user.id -->
                  <a class="btn btn--ghost" href="/admin/user.php?id=<?php echo urlencode($id); ?>">Керувати</a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

</body>
</html>
