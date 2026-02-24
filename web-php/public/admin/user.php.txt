<?php
declare(strict_types=1);

require_once __DIR__ . '/_guard.php';

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

  $list = [];

  if (isset($data['users']) && is_array($data['users'])) {
    $list = $data['users'];
  } else {
    // якщо це вже список або map
    $list = $data;
  }

  $out = [];

  // якщо це map виду {"id": {...}, "id2": {...}}
  $looksLikeMap = true;
  foreach ($list as $k => $v) {
    if (!is_array($v)) { $looksLikeMap = false; break; }
  }

  if ($looksLikeMap && !isset($data['users'])) {
    foreach ($list as $k => $u) {
      $id = (string)($u['id'] ?? $u['user_id'] ?? $k);
      $out[$id] = $u;
    }
    return $out;
  }

  // якщо список
  foreach ($list as $idx => $u) {
    if (!is_array($u)) continue;
    $id = (string)($u['id'] ?? $u['user_id'] ?? $idx);
    $out[$id] = $u;
  }

  return $out;
}

/**
 * ЗАПИС: завжди зберігає як { "users": [...] }
 */
function admin_save_users_fallback(array $users): void {
  $path = admin_users_json_path();

  $list = [];
  foreach ($users as $id => $u) {
    if (!is_array($u)) continue;
    if (empty($u['id'])) $u['id'] = (string)$id;
    $list[] = $u;
  }

  $data = ['users' => array_values($list)];

  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  if ($json === false) {
    http_response_code(500);
    echo "Не вдалось згенерувати JSON.";
    exit;
  }

  if (file_put_contents($path, $json) === false) {
    http_response_code(500);
    echo "Не вдалось записати users.json (перевір права доступу).";
    exit;
  }
}

function admin_load_users(): array {
  // якщо у тебе в users_store.php є функції — можеш підключити тут,
  // але для стабільності лишаємо fallback
  return admin_load_users_fallback();
}

function admin_save_users(array $users): void {
  admin_save_users_fallback($users);
}

function iso_or_empty(string $s): string {
  $s = trim($s);
  return $s;
}

function now_iso_utc(): string {
  return gmdate('c');
}

/* ==============
   LOAD USER
============== */

$id = (string)($_GET['id'] ?? '');
if ($id === '') admin_redirect('/admin/users.php');

$users = admin_load_users();

$realKey = null;
$user = null;

// 1) як ключ
if (isset($users[$id]) && is_array($users[$id])) {
  $realKey = $id;
  $user = $users[$id];
}

// 2) пошук по полю id
if (!$user) {
  foreach ($users as $k => $u) {
    if (!is_array($u)) continue;
    $uid = (string)($u['id'] ?? $u['user_id'] ?? '');
    if ($uid !== '' && $uid === $id) {
      $realKey = (string)$k;
      $user = $u;
      break;
    }
  }
}

if (!$user || $realKey === null) {
  http_response_code(404);
  echo "Користувача не знайдено.";
  exit;
}

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
    $users[$realKey]['plan_set_at'] = now_iso_utc();

    // paid_at ставимо тільки якщо план не free
    if ($plan !== 'free') {
      $users[$realKey]['paid_at'] = now_iso_utc();
    } else {
      unset($users[$realKey]['paid_at'], $users[$realKey]['expires_at']);
    }

    if ($plan !== 'free') {
      if ($days > 0) {
        $users[$realKey]['expires_at'] = gmdate('c', time() + ($days * 86400));
      } else {
        // 0 днів = без expires_at
        unset($users[$realKey]['expires_at']);
      }
    }

    admin_save_users($users);
    admin_redirect('/admin/user.php?id=' . urlencode($realKey) . '&ok=1');
  }

  if ($action === 'set_dates') {
    $paid_at = iso_or_empty((string)($_POST['paid_at'] ?? ''));
    $expires_at = iso_or_empty((string)($_POST['expires_at'] ?? ''));

    if ($paid_at !== '') $users[$realKey]['paid_at'] = $paid_at; else unset($users[$realKey]['paid_at']);
    if ($expires_at !== '') $users[$realKey]['expires_at'] = $expires_at; else unset($users[$realKey]['expires_at']);

    if ((!empty($users[$realKey]['paid_at']) || !empty($users[$realKey]['expires_at'])) && empty($users[$realKey]['plan'])) {
      $users[$realKey]['plan'] = 'personal';
      $users[$realKey]['plan_set_at'] = now_iso_utc();
    }

    admin_save_users($users);
    admin_redirect('/admin/user.php?id=' . urlencode($realKey) . '&ok=1');
  }

  if ($action === 'revoke') {
    $users[$realKey]['plan'] = 'free';
    $users[$realKey]['plan_set_at'] = now_iso_utc();
    unset($users[$realKey]['paid_at'], $users[$realKey]['expires_at']);
    admin_save_users($users);
    admin_redirect('/admin/user.php?id=' . urlencode($realKey) . '&ok=1');
  }

  if ($action === 'delete') {
    unset($users[$realKey]);
    admin_save_users($users);
    admin_redirect('/admin/users.php?deleted=1');
  }
}

$ok = (string)($_GET['ok'] ?? '');
$user = $users[$realKey] ?? $user;

// UI helpers
$planCur = (string)($user['plan'] ?? 'free');
$createdAt = (string)($user['created_at'] ?? $user['registered_at'] ?? '—');
$paidAt = (string)($user['paid_at'] ?? '');
$expiresAt = (string)($user['expires_at'] ?? '');
?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Користувач — Адмінка</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Unbounded:wght@500;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/style.css?v=6">

  <style>
    html, body { margin:0; padding:0; }
    .admin-header{
      padding:32px 0;
      background:linear-gradient(180deg,#eaf3ef 0,#ffffff 100%);
      position:relative;
    }
    .admin-actions{
      position:absolute; right:20px; top:20px; display:flex; gap:10px; flex-wrap:wrap;
    }
    .grid{
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap:14px;
      margin-bottom:26px;
    }
    @media (max-width: 900px){
      .grid{ grid-template-columns: 1fr; }
    }
    .field label{ font-weight:800; display:block; margin-bottom:6px; }
    .field input, .field select{
      width:100%;
      padding:12px 14px;
      border-radius:14px;
      border:1px solid rgba(11,27,20,.12);
      font-weight:700;
      outline:none;
      background:#fff;
    }
    .danger{
      border:1px solid rgba(255,0,0,.18);
      background:rgba(255,0,0,.04);
      border-radius:18px;
      padding:14px;
    }
    .mini{
      color:rgba(11,27,20,.65);
      font-weight:700;
      line-height:1.35;
      font-size:14px;
    }
  </style>
</head>
<body>

<div class="admin-header">
  <div class="container">
    <div class="admin-actions">
      <a class="btn btn--ghost" href="/admin/users.php">← Назад</a>
      <a class="btn btn--ghost" href="/">На сайт</a>
      <a class="btn btn--ghost" href="/admin/logout.php">Вийти</a>
    </div>

    <h1 class="h1">Користувач</h1>
    <p class="lead">
      <b>ID:</b> <?php echo h($user['id'] ?? $realKey); ?> ·
      <b>Email:</b> <?php echo h($user['email'] ?? '—'); ?> ·
      <b>План:</b> <?php echo h($planCur ?: 'free'); ?>
    </p>

    <?php if ($ok === '1'): ?>
      <div class="notice notice--ok" style="margin-top:12px;">Збережено ✅</div>
    <?php endif; ?>
  </div>
</div>

<div class="container" style="margin-top:18px;">
  <div class="grid">

    <!-- ====== GRANT PLAN ====== -->
    <div class="account-card">
      <h3 class="h3">Видати тарифний план</h3>
      <p class="mini" style="margin-top:8px;">
        План + термін у днях. <b>0 днів</b> = без expires_at. При видачі плану автоматично ставимо paid_at=now.
      </p>

      <form method="post" action="/admin/user.php?id=<?php echo urlencode($realKey); ?>" style="margin-top:12px;">
        <input type="hidden" name="csrf" value="<?php echo h(csrf_token_admin()); ?>">
        <input type="hidden" name="action" value="grant_plan">

        <div class="field" style="margin-bottom:10px;">
          <label>План</label>
          <select name="plan">
            <option value="free" <?php echo $planCur==='free'?'selected':''; ?>>free (без доступу)</option>
            <option value="dev" <?php echo $planCur==='dev'?'selected':''; ?>>dev (тестовий доступ)</option>
            <option value="basic" <?php echo $planCur==='basic'?'selected':''; ?>>basic</option>
            <option value="personal" <?php echo $planCur==='personal'?'selected':''; ?>>personal</option>
          </select>
        </div>

        <div class="field" style="margin-bottom:10px;">
          <label>Тривалість (днів)</label>
          <input type="number" name="days" value="30" min="0" step="1">
        </div>

        <button class="btn btn--primary" type="submit">Видати / Оновити план</button>
      </form>

      <form method="post" action="/admin/user.php?id=<?php echo urlencode($realKey); ?>" style="margin-top:12px;">
        <input type="hidden" name="csrf" value="<?php echo h(csrf_token_admin()); ?>">
        <input type="hidden" name="action" value="revoke">
        <button class="btn btn--ghost" type="submit">Забрати підписку (free)</button>
      </form>
    </div>

    <!-- ====== DATES ====== -->
    <div class="account-card">
      <h3 class="h3">Оплата / дати (ручне редагування)</h3>
      <p class="mini" style="margin-top:8px;">
        Формат ISO. Порожнє поле = видалити.
      </p>

      <form method="post" action="/admin/user.php?id=<?php echo urlencode($realKey); ?>" style="margin-top:12px;">
        <input type="hidden" name="csrf" value="<?php echo h(csrf_token_admin()); ?>">
        <input type="hidden" name="action" value="set_dates">

        <div class="field" style="margin-bottom:10px;">
          <label>paid_at</label>
          <input type="text" name="paid_at" value="<?php echo h($paidAt); ?>" placeholder="наприклад 2026-02-14T21:50:45+00:00">
        </div>

        <div class="field" style="margin-bottom:10px;">
          <label>expires_at</label>
          <input type="text" name="expires_at" value="<?php echo h($expiresAt); ?>" placeholder="наприклад 2026-03-16T21:50:45+00:00">
        </div>

        <button class="btn btn--primary" type="submit">Зберегти дати</button>
      </form>
    </div>

    <!-- ====== INFO ====== -->
    <div class="account-card">
      <h3 class="h3">Інфо</h3>
      <div class="sub-card" style="margin-top:12px;">
        <div><b>Ім’я:</b> <?php echo h($user['name'] ?? '—'); ?></div>
        <div style="margin-top:6px;"><b>Email:</b> <?php echo h($user['email'] ?? '—'); ?></div>
        <div style="margin-top:6px;"><b>Реєстрація:</b> <?php echo h($createdAt); ?></div>
        <div style="margin-top:6px;"><b>План:</b> <?php echo h($planCur ?: 'free'); ?></div>
        <div style="margin-top:6px;"><b>paid_at:</b> <?php echo h($paidAt !== '' ? $paidAt : '—'); ?></div>
        <div style="margin-top:6px;"><b>expires_at:</b> <?php echo h($expiresAt !== '' ? $expiresAt : '—'); ?></div>
        <div style="margin-top:6px;"><b>plan_set_at:</b> <?php echo h($user['plan_set_at'] ?? '—'); ?></div>
      </div>
    </div>

    <!-- ====== DELETE ====== -->
    <div class="account-card">
      <h3 class="h3">Небезпечно</h3>
      <div class="danger" style="margin-top:12px;">
        <p class="lead" style="margin:0 0 12px 0;">Видалення прибере користувача з users.json назавжди.</p>
        <form method="post" action="/admin/user.php?id=<?php echo urlencode($realKey); ?>"
              onsubmit="return confirm('Точно видалити користувача?');">
          <input type="hidden" name="csrf" value="<?php echo h(csrf_token_admin()); ?>">
          <input type="hidden" name="action" value="delete">
          <button class="btn btn--primary" type="submit" style="background:#c51616;border-color:#c51616;">
            Видалити користувача
          </button>
        </form>
      </div>
    </div>

  </div>
</div>

</body>
</html>
