<?php
declare(strict_types=1);

$bootstrap = __DIR__ . '/../../src/bootstrap.php';
$usersStore = __DIR__ . '/../../src/users_store.php';

if (is_file($bootstrap)) require_once $bootstrap;
if (is_file($usersStore)) require_once $usersStore;

if (session_status() !== PHP_SESSION_ACTIVE) {
  @session_start();
}

function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_read_array(string $path): array {
  if (!is_file($path)) return [];
  $raw = file_get_contents($path);
  if ($raw === false) return [];
  if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

$uid = $_SESSION['user_id'] ?? null;
if (!$uid) {
  header('Location: /login', true, 302);
  exit;
}
$uidStr = (string)$uid;

// ✅ sessions: enforce revoke + register current device session
if (function_exists('session_enforce_not_revoked')) {
  session_enforce_not_revoked($uidStr);
}
if (function_exists('session_register_current')) {
  session_register_current($uidStr);
}

// ---- user ----
$user = function_exists('user_find_by_id') ? user_find_by_id($uidStr) : null;

$nameRaw = (string)($user['name'] ?? ($_SESSION['user_name'] ?? 'Користувач'));
$email = (string)($user['email'] ?? ($_SESSION['user_email'] ?? ''));

$nameFirst = trim($nameRaw);
if ($nameFirst !== '') {
  $parts = preg_split('/\s+/u', $nameFirst);
  $nameFirst = $parts && isset($parts[0]) ? $parts[0] : $nameFirst;
} else {
  $nameFirst = 'Користувач';
}

$tab = (string)($_GET['tab'] ?? 'dashboard');
$allowedTabs = ['dashboard', 'subscriptions', 'tests', 'exam', 'trainer'];
if (!in_array($tab, $allowedTabs, true)) $tab = 'dashboard';

// ---- Access ----
$hasAccess = false;
if (is_array($user)) {
  if (!empty($user['plan'])) $hasAccess = true; // basic
  if (!empty($user['subscription']) || !empty($user['subscription_until']) || !empty($user['expires_at'])) $hasAccess = true;
}
if (!empty($_SESSION['has_access'])) $hasAccess = true;

// ✅ account actions: change password + sessions revoke
function _account_redirect(string $url): void {
  header('Location: ' . $url, true, 302);
  exit;
}

$currentSid = session_id();
if (!is_string($currentSid)) $currentSid = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // CSRF (якщо є в bootstrap.php)
  if (function_exists('csrf_verify')) {
    csrf_verify($_POST['csrf'] ?? null);
  }

  $action = (string)($_POST['action'] ?? '');

  if ($action === 'change_password') {
    $old = (string)($_POST['old_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $new2 = (string)($_POST['new_password2'] ?? '');

    $u = function_exists('user_find_by_id') ? user_find_by_id($uidStr) : null;
    if (!is_array($u)) {
      _account_redirect('/logout');
    }

    if ($new === '' || mb_strlen($new) < 6) {
      _account_redirect('/account?tab=dashboard&err=pwd_short');
    }
    if ($new !== $new2) {
      _account_redirect('/account?tab=dashboard&err=pwd_mismatch');
    }
    if (!password_verify($old, (string)($u['password_hash'] ?? ''))) {
      _account_redirect('/account?tab=dashboard&err=pwd_old');
    }

    if (!function_exists('user_update')) {
      _account_redirect('/account?tab=dashboard&err=pwd_fail');
    }

    user_update($uidStr, ['password_hash' => password_hash($new, PASSWORD_DEFAULT)]);

    // після зміни пароля — скидаємо всі інші сесії
    if (function_exists('sessions_revoke_all_for_user')) {
      sessions_revoke_all_for_user($uidStr, $currentSid !== '' ? $currentSid : null);
    }

    _account_redirect('/account?tab=dashboard&ok=pwd');
  }

  if ($action === 'revoke_session') {
    $sid = (string)($_POST['sid'] ?? '');
    if ($sid !== '' && $sid !== $currentSid && function_exists('session_revoke_for_user')) {
      session_revoke_for_user($uidStr, $sid);
    }
    _account_redirect('/account?tab=dashboard&ok=sessions');
  }

  if ($action === 'revoke_all_other') {
    if (function_exists('sessions_revoke_all_for_user')) {
      sessions_revoke_all_for_user($uidStr, $currentSid !== '' ? $currentSid : null);
    }
    _account_redirect('/account?tab=dashboard&ok=sessions');
  }
}

// ---- Заглушка підписок ----
$subscription = [
  'plan' => '—',
  'status' => '—',
  'expires_at' => '—',
];

// ---- progress.json (помилки + пройдені тести) ----
function progress_path(): string {
  return dirname(__DIR__, 2) . '/storage/progress.json';
}
function progress_user_bucket(string $uid): array {
  $p = progress_path();
  $data = json_read_array($p);
  $users = $data['users'] ?? null;
  if (!is_array($users)) return [];
  $u = $users[$uid] ?? null;
  return is_array($u) ? $u : [];
}

$uProgress = progress_user_bucket($uidStr);
$passedTestsMap = $uProgress['passed_tests'] ?? [];
if (!is_array($passedTestsMap)) $passedTestsMap = [];
$passedTestIds = [];
foreach ($passedTestsMap as $k => $v) {
  if ($v) $passedTestIds[] = (int)$k;
}
$passedTestIds = array_values(array_filter($passedTestIds, fn($x)=>$x>0));

$mistakesByTest = $uProgress['mistakes'] ?? [];
if (!is_array($mistakesByTest)) $mistakesByTest = [];

$mistakeSet = [];
foreach ($mistakesByTest as $list) {
  if (!is_array($list)) continue;
  foreach ($list as $qid) {
    $qid = (int)$qid;
    if ($qid > 0) $mistakeSet[$qid] = true;
  }
}
$mistakesCount = count($mistakeSet);

// ---- Read exports for progress ----
$dataDir = realpath(__DIR__ . '/../data');
$questionsExport = $dataDir ? ($dataDir . '/questions_export.json') : '';
$testsExport = $dataDir ? ($dataDir . '/tests_export.json') : '';

$questionsArr = $questionsExport ? json_read_array($questionsExport) : [];
$totalQuestions = is_array($questionsArr) ? count($questionsArr) : 0;

// Всі тести (type=test)
$testsArr = $testsExport ? json_read_array($testsExport) : [];
$allTests = [];
foreach ($testsArr as $t) {
  if (!is_array($t)) continue;
  if ((string)($t['type'] ?? '') !== 'test') continue;
  $tid = (int)($t['id'] ?? 0);
  if ($tid > 0) $allTests[$tid] = $t;
}
$totalTests = count($allTests);

// Покриті питання = помилки + питання з пройдених тестів (унікальні)
$coveredSet = $mistakeSet;

foreach ($passedTestIds as $tid) {
  $t = $allTests[$tid] ?? null;
  if (!is_array($t)) continue;
  $qids = $t['question_ids'] ?? [];
  if (!is_array($qids)) continue;
  foreach ($qids as $qid) {
    $qid = (int)$qid;
    if ($qid > 0) $coveredSet[$qid] = true;
  }
}

$coveredQuestions = count($coveredSet);
$progressPercent = 0;
if ($totalQuestions > 0) {
  $progressPercent = (int)round(($coveredQuestions / $totalQuestions) * 100);
  $progressPercent = max(0, min(100, $progressPercent));
}

// Скільки тестів пройдено
$passedTestsCount = 0;
foreach ($passedTestIds as $tid) {
  if (isset($allTests[$tid])) $passedTestsCount++;
}
?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Кабінет — ProstoPDR</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Unbounded:wght@400;600;700;800;900&family=Manrope:wght@400;600;700;800;900&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="/assets/css/style.css?v=8" />
  <style>
  .note{border-radius:14px; padding:12px 14px; font-weight:800;}
  .note--ok{background:rgba(10,122,61,.10); border:1px solid rgba(10,122,61,.25); color:#0b1b14;}
  .note--bad{background:rgba(220,38,38,.10); border:1px solid rgba(220,38,38,.25); color:#0b1b14;}
  .label{font-weight:900;}
  </style>
</head>

<body class="page-account">

<header class="site-header">
  <div class="container header-inner">
    <a class="brand" href="/" aria-label="ProstoPDR">
      <img class="brand__logo" src="/assets/img/logo.svg" alt="" />
    </a>

    <nav class="nav">
      <a href="/#structure">структура</a>
      <a href="/#pricing">тарифи</a>
      <a href="/#program">програма</a>
      <a href="/#faq">faq</a>
    </nav>

    <div class="usermenu">
      <button class="usermenu__btn" type="button" id="userMenuBtn">
        <span class="usermenu__avatar">🎓</span>
        <span class="usermenu__name"><?= h($nameFirst) ?></span>
        <span class="usermenu__chev">▾</span>
      </button>

      <div class="usermenu__drop" id="userMenuDrop">
        <a class="usermenu__item" href="/account"><span class="usermenu__icon">👤</span> Кабінет</a>
        <a class="usermenu__item" href="#"><span class="usermenu__icon">🧑‍</span> Викладач</a>
        <a class="usermenu__item" href="/account?tab=subscriptions"><span class="usermenu__icon">💳</span> Мої підписки</a>
        <a class="usermenu__item" href="/"><span class="usermenu__icon">🏠</span> На головну</a>
        <a class="usermenu__item usermenu__item--danger" href="/logout"><span class="usermenu__icon">↩</span> Вийти</a>
      </div>
    </div>
  </div>
</header>

<main class="account-main">
  <div class="container">

    <div class="account-head">
      <h1 class="h1">Кабінет</h1>
      <p class="lead">Керуйте навчанням, підпискою та прогресом.</p>
    </div>

    <div class="account-tabs">
      <a class="account-tab <?= $tab==='dashboard'?'is-active':''; ?>" href="/account">Кабінет</a>
      <a class="account-tab <?= $tab==='subscriptions'?'is-active':''; ?>" href="/account?tab=subscriptions">Мої підписки</a>
      <a class="account-tab <?= $tab==='tests'?'is-active':''; ?>" href="/account/tests.php?mode=tests">Тести</a>
      <a class="account-tab <?= $tab==='exam'?'is-active':''; ?>" href="/account/tests.php?mode=exam">Іспит</a>
      <a class="account-tab <?= $tab==='trainer'?'is-active':''; ?>" href="/account/tests.php?mode=trainer">Тренажер</a>
    </div>

    <?php if ($tab === 'dashboard'): ?>

      <div class="dash-top">

        <!-- PRICING -->
        <div class="dash-left">
          <div class="account-block" id="pricing">
            <h3 class="h3">Обрати тариф</h3>

            <div class="plans">
              <article class="plan plan--primary" id="planCard">
                <div class="plan__top">
                  <div class="plan__badge">Базовий план</div>
                  <h2 class="plan__title">Базовий план<br/>підписка</h2>
                  <div class="plan__price">49₴ <span>/ 30 днів</span></div>
                </div>

                <ul class="plan__list">
                  <li>Доступ до всіх тестів</li>
                  <li>Режим «іспит»</li>
                  <li>Пояснення до відповідей</li>
                  <li>Статистика прогресу</li>
                </ul>

                <div class="plan__cta-wrap">
                  <a class="btn btn--primary plan__cta" href="/checkout?plan=basic">Обрати</a>
                </div>
              </article>

              <article class="plan plan--ghost">
                <div class="plan__top">
                  <div class="plan__badge">Тестовий доступ</div>
                  <h2 class="plan__title">План на 12 днів</h2>
                  <div class="plan__price">29₴ <span>/ 12 днів</span></div>
                </div>

                <ul class="plan__list">
                  <li>Доступ до тестів</li>
                  <li>Пояснення</li>
                  <li>Прогрес</li>
                  <li>Підготовка до іспиту</li>
                </ul>

                <div class="plan__cta-wrap">
                  <a class="btn btn--ghost plan__cta" href="/demo">Отримати 3 дні безкоштовно</a>
                  <a class="btn btn--primary plan__cta" href="/checkout?plan=mini12">Обрати</a>
                </div>
              </article>

            </div>
          </div>
        </div>

        <!-- PROGRESS -->
        <aside class="dash-right">
          <div class="dash-right-head" aria-hidden="true"></div>

          <div class="progress-card" id="progressCard">
            <h3 class="progress-title">Твій прогрес</h3>

            <div class="ring-wrap">
              <div class="ring-box">
                <svg class="ring" viewBox="0 0 200 200" aria-label="Progress ring">
                  <circle class="ring-bg" cx="100" cy="100" r="70"></circle>
                  <circle class="ring-fill" cx="100" cy="100" r="70" data-percent="<?= (int)$progressPercent ?>"></circle>
                </svg>
                <div class="ring-center">
                  <div class="ring-percent"><?= (int)$progressPercent ?>%</div>
                  <div class="ring-sub">покрито питань</div>
                </div>
              </div>
            </div>

            <div class="stats-grid">
              <div class="stat">
                <div class="stat-val"><?= (int)$coveredQuestions ?></div>
                <div class="stat-lbl">Покрито</div>
              </div>
              <div class="stat">
                <div class="stat-val"><?= (int)$totalQuestions ?></div>
                <div class="stat-lbl">Питань</div>
              </div>
              <div class="stat">
                <div class="stat-val"><?= (int)$passedTestsCount ?></div>
                <div class="stat-lbl">Пройдено тестів</div>
              </div>
              <div class="stat">
                <div class="stat-val"><?= (int)$mistakesCount ?></div>
                <div class="stat-lbl">Помилки</div>
              </div>
            </div>

         
          </div>
        </aside>

      </div>

      <!-- LEARNING -->
      <div class="account-block">
        <h3 class="h3">Навчання</h3>

        <div class="sub-grid" style="margin-top:12px;">
          <div class="sub-card study-card <?= !$hasAccess ? 'is-locked' : ''; ?>" style="background:#fff;">
            <?php if (!$hasAccess): ?><div class="study-card__lock" title="Доступ закрито">🔒</div><?php endif; ?>
            <div class="study-card__title" style="font-weight:900;margin-bottom:6px;">Тести</div>
            <div style="color:rgba(11,27,20,.65);font-weight:700;line-height:1.4;">
              Тренуйся по темах та змішаних тестах.
            </div>
            <div style="margin-top:12px;">
              <a class="btn btn--primary" href="<?= $hasAccess ? '/account/tests.php?mode=tests' : '/account?tab=dashboard#pricing'; ?>">Перейти</a>
            </div>
          </div>

          <div class="sub-card study-card <?= !$hasAccess ? 'is-locked' : ''; ?>" style="background:#fff;">
            <?php if (!$hasAccess): ?><div class="study-card__lock" title="Доступ закрито">🔒</div><?php endif; ?>
            <div class="study-card__title" style="font-weight:900;margin-bottom:6px;">Іспит</div>
            <div style="color:rgba(11,27,20,.65);font-weight:700;line-height:1.4;">
              Режим іспиту з таймером та лімітом помилок.
            </div>
            <div style="margin-top:12px;">
              <a class="btn btn--primary" href="<?= $hasAccess ? '/account/tests.php?mode=exam' : '/account?tab=dashboard#pricing'; ?>">Перейти</a>
            </div>
          </div>

          <div class="sub-card study-card <?= !$hasAccess ? 'is-locked' : ''; ?>" style="background:#fff;">
            <?php if (!$hasAccess): ?><div class="study-card__lock" title="Доступ закрито">🔒</div><?php endif; ?>
            <div class="study-card__title" style="font-weight:900;margin-bottom:6px;">Тренажер</div>
            <div style="color:rgba(11,27,20,.65);font-weight:700;line-height:1.4;">
              Випадкові питання, повтор помилок та прогрес.
            </div>
            <div style="margin-top:12px;">
              <a class="btn btn--primary" href="<?= $hasAccess ? '/account/tests.php?mode=trainer' : '/account?tab=dashboard#pricing'; ?>">Перейти</a>
            </div>
          </div>
        </div>
      </div>

      <div class="account-grid">
        <div class="account-card">

          <div class="account-card">
            <h3 class="h3">Працювати над помилками</h3>
            <div class="lead" style="margin-top:8px;">
              У вас <b><?= (int)$mistakesCount ?></b> унікальних помилок.
            </div>

            <?php if ($mistakesCount === 0): ?>
              <div class="lead" style="margin-top:10px;">
                Помилок ще немає — проходьте тести, і тут з’явиться повтор.
              </div>
            <?php else: ?>
              <div style="margin-top:12px;">
                <a class="btn btn--primary" href="<?= $hasAccess ? '/account/tests.php?mode=trainer&mistakes=1' : '/account?tab=dashboard#pricing'; ?>">Повторити помилки</a>
              </div>
            <?php endif; ?>
          </div>

          <div class="account-card">
            <h3 class="h3">Швидкі дії</h3>
            <div style="display:flex; gap:12px; flex-wrap:wrap; margin-top:12px;">
              <a class="btn btn--primary" href="<?= $hasAccess ? '/account/tests.php?mode=exam' : '/account?tab=dashboard#pricing'; ?>">Пробне тестування</a>
              <a class="btn btn--ghost" href="<?= $hasAccess ? '/account/tests.php?mode=trainer&mistakes=1' : '/account?tab=dashboard#pricing'; ?>">Повтор помилок</a>
            </div>
          </div>

        </div>

      </div>

          <div class="account-card">
            <h3 class="h3">Безпека</h3>

            <?php if (!empty($_GET['ok']) && $_GET['ok']==='pwd'): ?>
              <div class="note note--ok" style="margin-top:10px;">✅ Пароль змінено. Інші сеанси завершені.</div>
            <?php endif; ?>
            <?php if (!empty($_GET['ok']) && $_GET['ok']==='sessions'): ?>
              <div class="note note--ok" style="margin-top:10px;">✅ Сеанси оновлено.</div>
            <?php endif; ?>

            <?php if (!empty($_GET['err'])): ?>
              <div class="note note--bad" style="margin-top:10px;">
                <?php
                  $e = (string)$_GET['err'];
                  $msg = 'Помилка.';
                  if ($e === 'pwd_short') $msg = 'Новий пароль має бути мінімум 6 символів.';
                  elseif ($e === 'pwd_mismatch') $msg = 'Паролі не співпадають.';
                  elseif ($e === 'pwd_old') $msg = 'Старий пароль невірний.';
                  elseif ($e === 'pwd_fail') $msg = 'Не вдалося змінити пароль.';
                  echo h($msg);
                ?>
              </div>
            <?php endif; ?>

            <div class="sub-card" style="margin-top:12px;">
              <div class="sub-card__row">
                <div class="sub-card__label">Ваш ID</div>
                <div class="sub-card__value"><b><?= h((string)$uidStr) ?></b></div>
              </div>
            </div>

            <div style="margin-top:14px;">
              <div style="font-weight:900; margin-bottom:8px;">Змінити пароль</div>

              <form method="post" class="form" style="display:grid; gap:10px; max-width:520px;">
                <input type="hidden" name="csrf" value="<?= h(function_exists('csrf_token') ? (string)csrf_token() : '') ?>">
                <input type="hidden" name="action" value="change_password">

                <label class="label">Старий пароль</label>
                <input class="input" type="password" name="old_password" required>

                <label class="label">Новий пароль</label>
                <input class="input" type="password" name="new_password" required>

                <label class="label">Повторіть новий пароль</label>
                <input class="input" type="password" name="new_password2" required>

                <button class="btn btn--primary" type="submit">Змінити пароль</button>
              </form>

              <form method="post" style="margin-top:10px;">
                <input type="hidden" name="csrf" value="<?= h(function_exists('csrf_token') ? (string)csrf_token() : '') ?>">
                <input type="hidden" name="action" value="revoke_all_other">
                <button class="btn btn--ghost" type="submit">Вийти з усіх інших пристроїв</button>
              </form>
            </div>

            <div style="margin-top:14px;">
              <div style="font-weight:900; margin-bottom:8px;">Активні сеанси</div>

              <?php $sessions = function_exists('sessions_list_for_user') ? sessions_list_for_user($uidStr) : []; ?>
              <?php if (empty($sessions)): ?>
                <div class="lead">Немає активних сесій.</div>
              <?php else: ?>
                <div style="display:grid; gap:10px;">
                  <?php foreach ($sessions as $s):
                    $sid = (string)($s['sid'] ?? '');
                    $isThis = ($sid !== '' && $sid === $currentSid);
                  ?>
                    <div class="sub-card" style="background:#fff;">
                      <div class="sub-card__row">
                        <div class="sub-card__label"><?= $isThis ? 'Цей пристрій ✅' : 'Пристрій' ?></div>
                        <div class="sub-card__value" style="opacity:.75; font-weight:800; font-size:13px;">
                          IP: <?= h((string)($s['ip'] ?? '')) ?><br>
                          UA: <?= h((string)($s['ua'] ?? '')) ?><br>
                          Створено: <?= h((string)($s['created_at'] ?? '')) ?><br>
                          Остання активність: <?= h((string)($s['last_seen'] ?? '')) ?>
                        </div>
                      </div>

                      <?php if (!$isThis): ?>
                        <div style="padding:0 14px 14px 14px;">
                          <form method="post">
                            <input type="hidden" name="csrf" value="<?= h(function_exists('csrf_token') ? (string)csrf_token() : '') ?>">
                            <input type="hidden" name="action" value="revoke_session">
                            <input type="hidden" name="sid" value="<?= h($sid) ?>">
                            <button class="btn btn--ghost" type="submit">Завершити сеанс</button>
                          </form>
                        </div>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>

          </div>



    <?php else: ?>

      <div class="account-grid">
        <div class="account-card">
          <h3 class="h3">Мої підписки</h3>

          <div class="sub-card">
            <div class="sub-card__row">
              <div class="sub-card__label">План</div>
              <div class="sub-card__value"><?= h((string)$subscription['plan']) ?></div>
            </div>
            <div class="sub-card__row">
              <div class="sub-card__label">Статус</div>
              <div class="sub-card__value"><?= h((string)$subscription['status']) ?></div>
            </div>
            <div class="sub-card__row">
              <div class="sub-card__label">Діє до</div>
              <div class="sub-card__value"><?= h((string)$subscription['expires_at']) ?></div>
            </div>

            <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;">
              <a class="btn btn--primary" href="/#pricing">Змінити тариф</a>
              <a class="btn btn--ghost" href="#">Скасувати підписку</a>
            </div>
          </div>
        </div>

        <div class="account-card">
          <h3 class="h3">Оплата</h3>
          <p class="lead">Далі додамо історію платежів та чек.</p>
        </div>
      </div>

    <?php endif; ?>

  </div>
</main>

<script src="/assets/js/main.js?v=4"></script>

<script>
(function(){
  // ring fill (radius = 70 має відповідати r="70" в SVG)
  const c = document.querySelector('.ring-fill');
  if(c){
    const percent = parseInt(c.getAttribute('data-percent') || '0', 10);
    const radius = 70;
    const circumference = 2 * Math.PI * radius;
    c.style.strokeDasharray = String(circumference);
    const p = Math.max(0, Math.min(100, percent));
    const offset = circumference - (p / 100) * circumference;
    c.style.strokeDashoffset = String(offset);
  }

  // sync heights (✅ FIX: без h до оголошення)
  const plan = document.getElementById('planCard');
  const prog = document.getElementById('progressCard');

  function syncHeights(){
    if(!plan || !prog) return;
    const isDesktop = window.matchMedia('(min-width: 1100px)').matches;
    if(!isDesktop){
      prog.style.height = '';
      return;
    }
    prog.style.height = '';
    const h = plan.offsetHeight;
    prog.style.height = h + 'px';
  }

  window.addEventListener('load', syncHeights);
  window.addEventListener('resize', syncHeights);
  if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(syncHeights).catch(()=>{});
  }
})();
</script>

</body>
</html>