<?php
declare(strict_types=1);

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../../src/users_store.php';
require_once __DIR__ . '/../../src/sessions_store.php';
require_once __DIR__ . '/../../src/chat_store.php';
require_once __DIR__ . '/../../src/activity_store.php';

if (session_status() !== PHP_SESSION_ACTIVE) @session_start();

function h($v): string {
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

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

function fmt_seconds_admin(int $seconds): string {
  $seconds = max(0, $seconds);

  $h = intdiv($seconds, 3600);
  $m = intdiv($seconds % 3600, 60);
  $s = $seconds % 60;

  if ($h > 0) return $h . ' г ' . $m . ' хв';
  if ($m > 0) return $m . ' хв ' . $s . ' с';
  return $s . ' с';
}

function fmt_dt_admin(?string $iso): string {
  $iso = trim((string)$iso);
  if ($iso === '' || strtolower($iso) === 'null') return '—';

  try {
    $dt = new DateTimeImmutable($iso);
    $dt = $dt->setTimezone(new DateTimeZone('Europe/Kyiv'));
    return $dt->format('Y-m-d H:i:s');
  } catch (Throwable $e) {
    return $iso;
  }
}

function parse_admin_dt(?string $value): ?string {
  $value = trim((string)$value);
  if ($value === '') return null;

  $ts = strtotime($value);
  if ($ts === false) return null;

  return gmdate('c', $ts);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  admin_csrf_verify($_POST['csrf'] ?? null);

  $action = (string)($_POST['action'] ?? '');

  if ($action === 'grant_plan') {
    $plan = strtolower(trim((string)($_POST['plan'] ?? 'free')));
    if (!in_array($plan, ['free','basic','personal','dev'], true)) $plan = 'free';

    $days = (int)($_POST['days'] ?? 0);
    $expiresAtInput = trim((string)($_POST['expires_at'] ?? ''));
    $paidAtInput = trim((string)($_POST['paid_at'] ?? ''));
    $planSetAtInput = trim((string)($_POST['plan_set_at'] ?? ''));

    $user['plan'] = $plan;

    // paid_at:
    // - не стираємо стару дату покупки
    // - якщо введено вручну — беремо її
    // - якщо план стає платним і paid_at ще не було — ставимо зараз
    if ($paidAtInput !== '') {
      $parsedPaidAt = parse_admin_dt($paidAtInput);
      if ($parsedPaidAt === null) {
        $error = 'Невірна дата paid_at.';
      } else {
        $user['paid_at'] = $parsedPaidAt;
      }
    } else {
      $currentPaidAt = trim((string)($user['paid_at'] ?? ''));
      if ($plan !== 'free' && $currentPaidAt === '') {
        $user['paid_at'] = gmdate('c');
      }
    }

    if ($planSetAtInput !== '') {
      $parsedPlanSetAt = parse_admin_dt($planSetAtInput);
      if ($parsedPlanSetAt === null) {
        $error = 'Невірна дата plan_set_at.';
      } else {
        $user['plan_set_at'] = $parsedPlanSetAt;
      }
    } else {
      $user['plan_set_at'] = gmdate('c');
    }

    if ($error === '') {
      if ($plan === 'free') {
        $user['expires_at'] = null;
      } else {
        if ($expiresAtInput !== '') {
          $ts = strtotime($expiresAtInput);
          if ($ts === false) {
            $error = 'Невірна дата expires_at.';
          } else {
            $user['expires_at'] = gmdate('c', $ts);
          }
        } else {
          if ($days <= 0) $days = 30;

          $baseTs = time();
          $currentExpTs = strtotime((string)($user['expires_at'] ?? ''));
          if ($currentExpTs !== false && $currentExpTs > $baseTs) {
            $baseTs = $currentExpTs;
          }

          $user['expires_at'] = gmdate('c', $baseTs + $days * 86400);
        }
      }
    }

    if ($error === '') {
      $user = user_upsert($user);
      $notice = 'Підписку оновлено (Postgres) без скидання історичних дат.';
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
    if (function_exists('user_delete')) {
      user_delete($uid);
      header('Location: /admin/users.php', true, 302);
      exit;
    } else {
      $error = 'user_delete() не знайдено в users_store.php.';
    }
  }

  $user = user_find_by_id($uid);
}

$activity = activity_get_user_summary($uid);
$attempts = activity_get_user_attempts($uid, 30);

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
    .inputx{padding:12px;border-radius:12px;border:1px solid rgba(0,0,0,.12);font-weight:800;width:100%;box-sizing:border-box}
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

        <div class="muted">Created</div>
        <div style="font-weight:900;margin-bottom:10px;"><?= h(fmt_dt_admin((string)($user['created_at'] ?? ''))) ?></div>

        <div class="muted">Paid at</div>
        <div style="font-weight:900;margin-bottom:10px;"><?= h(fmt_dt_admin((string)($user['paid_at'] ?? ''))) ?></div>

        <div class="muted">Expires</div>
        <div style="font-weight:900;margin-bottom:10px;"><?= h(fmt_dt_admin((string)($user['expires_at'] ?? ''))) ?></div>

        <div class="muted">Plan set at</div>
        <div style="font-weight:900;margin-bottom:10px;"><?= h(fmt_dt_admin((string)($user['plan_set_at'] ?? ''))) ?></div>

        <div class="muted">Mono last payment at</div>
        <div style="font-weight:900;"><?= h(fmt_dt_admin((string)($user['mono_last_payment_at'] ?? ''))) ?></div>
      </div>

      <div class="account-card col">
        <div class="h3" style="margin-top:0;">Керування підпискою</div>

        <form method="post" action="/admin/user.php?id=<?= urlencode($uid) ?>" style="display:grid;gap:10px;margin:0">
          <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
          <input type="hidden" name="action" value="grant_plan">

          <label style="font-weight:900;">Plan</label>
          <select class="inputx" name="plan">
            <?php $pl = (string)($user['plan'] ?? 'free'); ?>
            <option value="free" <?= $pl==='free'?'selected':''; ?>>free</option>
            <option value="basic" <?= $pl==='basic'?'selected':''; ?>>basic</option>
            <option value="personal" <?= $pl==='personal'?'selected':''; ?>>personal</option>
            <option value="dev" <?= $pl==='dev'?'selected':''; ?>>dev</option>
          </select>

          <div class="muted">Варіант 1: дні продовження. Якщо підписка ще активна — продовжить від поточного expires_at.</div>
          <input class="inputx" type="number" name="days" placeholder="Напр. 30" min="0">

          <div class="muted">Варіант 2: конкретна дата expires_at (YYYY-MM-DD або ISO)</div>
          <input class="inputx" type="text" name="expires_at" placeholder="<?= h((string)($user['expires_at'] ?? '2026-03-31')) ?>">

          <div class="muted">paid_at вручну. Якщо пусто — стара дата не стирається.</div>
          <input class="inputx" type="text" name="paid_at" placeholder="<?= h((string)($user['paid_at'] ?? '2026-03-01 12:00:00')) ?>">

          <div class="muted">plan_set_at вручну. Якщо пусто — ставиться поточний час.</div>
          <input class="inputx" type="text" name="plan_set_at" placeholder="<?= h((string)($user['plan_set_at'] ?? '2026-03-26 12:00:00')) ?>">

          <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:6px;">
            <button class="btn btn--primary" type="submit">Зберегти</button>
            <a class="btn btn--ghost" href="/admin/user.php?id=<?= urlencode($uid) ?>">Оновити</a>
          </div>
        </form>

        <div style="height:14px"></div>

        <form method="post" action="/admin/user.php?id=<?= urlencode($uid) ?>" style="margin:0;">
          <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
          <input type="hidden" name="action" value="reset_sessions">
          <button class="btn btn--ghost" type="submit">Скинути активні сесії</button>
        </form>

        <div style="height:14px"></div>

        <form method="post" action="/admin/user.php?id=<?= urlencode($uid) ?>" style="margin:0;"
              onsubmit="return confirm('Точно видалити користувача?');">
          <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
          <input type="hidden" name="action" value="delete_user">
          <button class="btn btn--ghost danger" type="submit">Видалити користувача</button>
        </form>
      </div>
    </div>

    <div class="account-card" style="margin-top:12px;">
      <div class="h3" style="margin-top:0;">Активність користувача</div>

      <div class="row">
        <div class="col">
          <div class="muted">Останній вхід</div>
          <div style="font-weight:900;margin-bottom:10px;"><?= h(fmt_dt_admin((string)($activity['last_login_at'] ?? ''))) ?></div>

          <div class="muted">Остання активність</div>
          <div style="font-weight:900;margin-bottom:10px;"><?= h(fmt_dt_admin((string)($activity['last_seen_at'] ?? ''))) ?></div>

          <div class="muted">Остання сторінка</div>
          <div style="font-weight:900;word-break:break-word;"><?= h((string)($activity['last_page'] ?? '—')) ?></div>
        </div>

        <div class="col">
          <div class="muted">Час на сайті</div>
          <div style="font-weight:900;margin-bottom:10px;"><?= h(fmt_seconds_admin((int)($activity['total_site_seconds'] ?? 0))) ?></div>

          <div class="muted">Почато тестів</div>
          <div style="font-weight:900;margin-bottom:10px;"><?= (int)($activity['tests_started'] ?? 0) ?></div>

          <div class="muted">Завершено тестів</div>
          <div style="font-weight:900;margin-bottom:10px;"><?= (int)($activity['tests_finished'] ?? 0) ?></div>

          <div class="muted">Усього правильних / помилок</div>
          <div style="font-weight:900;">
            <?= (int)($activity['total_correct_answers'] ?? 0) ?> / <?= (int)($activity['total_wrong_answers'] ?? 0) ?>
          </div>
        </div>
      </div>
    </div>

    <div class="account-card" style="margin-top:12px;">
      <div class="h3" style="margin-top:0;">Останні проходження тестів</div>

      <?php if (empty($attempts)): ?>
        <div class="muted">Поки нема зібраних даних по тестах.</div>
      <?php else: ?>
        <table class="tbl">
          <thead>
            <tr>
              <th>Тест</th>
              <th>Режим</th>
              <th>Старт</th>
              <th>Фініш</th>
              <th>Час</th>
              <th>Питань</th>
              <th>Прав.</th>
              <th>Помилки</th>
              <th>Статус</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($attempts as $a): ?>
              <tr>
                <td style="font-weight:900;"><?= h((string)($a['test_title'] ?? '—')) ?></td>
                <td class="muted"><?= h((string)($a['test_mode'] ?? '—')) ?></td>
                <td class="muted"><?= h(fmt_dt_admin((string)($a['started_at'] ?? ''))) ?></td>
                <td class="muted"><?= h(fmt_dt_admin((string)($a['finished_at'] ?? ''))) ?></td>
                <td class="muted"><?= h(fmt_seconds_admin((int)($a['duration_seconds'] ?? 0))) ?></td>
                <td class="muted"><?= (int)($a['total_questions'] ?? 0) ?></td>
                <td class="muted"><?= (int)($a['correct_answers'] ?? 0) ?></td>
                <td class="muted"><?= (int)($a['wrong_answers'] ?? 0) ?></td>
                <td class="muted"><?= h((string)($a['status'] ?? '—')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
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
                    <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
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