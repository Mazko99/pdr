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
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Unbounded:wght@500;700&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="/assets/css/style.css?v=4" />

  <style>
    .study-card{position:relative;}
    .study-card.is-locked{opacity:.92;}
    .study-card__lock{
      position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:999px;
      display:flex;align-items:center;justify-content:center;
      background: rgba(12,32,22,.08);border:1px solid rgba(12,32,22,.10);
      font-size:16px;line-height:1;pointer-events:none;user-select:none;
    }

    .dash-split{
      display:grid;
      gap:14px;
      margin-top:14px;
      grid-template-columns: 1fr;
    }
    @media (min-width: 900px){
      .dash-split{grid-template-columns: 1fr 1fr;align-items:start;}
    }

    /* TOP: 2 колонки */
    .dash-top{
      display:grid;
      grid-template-columns: 1fr;
      gap:16px;
      align-items:start;
      margin-bottom: 28px;
    }
    @media (min-width: 1100px){
      .dash-top{
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        gap:22px;
        align-items:start;
      }
      .dash-right{position:static;top:auto;}
    }

    /* Тариф */
    .pricing.pricing--account{display:block;width:100%;}
    .pricing.pricing--account .plan{width:100%;max-width:none;}

    /* ✅ ШАПКА справа (ти виставив позиціювання — залишив як є) */
    .dash-right-head{
      margin:0 0 12px;
      height: 54px;
    }
    @media (max-width:1099px){
      .dash-right-head{display:none;}
    }

    /* ===========================
       ✅ ПРОГРЕС (ЗБІЛЬШЕНО ЯК ТИ ПРОСИВ)
       =========================== */
    .progress-card{
      background:#fff;
      border-radius:18px;
      padding:30px;
      box-shadow:0 8px 30px rgba(0,0,0,0.06);
      border:1px solid rgba(12,32,22,.06);
      width:90%;
      overflow:hidden;
      display:flex;
      flex-direction:column;
    }

    /* Заголовок більший */
    .progress-title{
      font-weight:900;
      font-size:22px;
      margin:0 0 14px;
      text-align:center;
      letter-spacing:.2px;
    }

    /* Кільце більше */
    .ring-wrap{
      display:flex;
      justify-content:center;
      align-items:center;
      margin-top:6px;
      margin-bottom:16px;
      flex:0 0 auto;
    }
    .ring{width:190px;height:190px;display:block;}
    .ring-bg{fill:none;stroke: rgba(11,27,20,.10);stroke-width: 14;}
    .ring-fill{
      fill:none;stroke:#22c55e;stroke-width:14;stroke-linecap:round;
      transform: rotate(-90deg);
      transform-origin: 50% 50%;
      stroke-dasharray: 439.82;
      stroke-dashoffset: 439.82;
      transition: stroke-dashoffset .9s ease;
    }
    .ring-box{
      position:relative;
      width:190px;height:190px;
      display:flex;align-items:center;justify-content:center;
    }
    .ring-center{position:absolute;text-align:center;transform: translateY(-2px);}
    .ring-percent{font-size:36px;font-weight:900;color:#0a7a3d;line-height:1;}
    .ring-sub{font-weight:800;opacity:.7;font-size:13px;margin-top:8px;}

    /* Квадратики більші + текст "нижче" */
    .stats-grid{
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap:14px;
      margin-top:6px;
      flex:1 1 auto;
      align-content:start;
      max-width: 360px;
      margin-left:auto;
      margin-right:auto;
    }
    .stat{
      border:1px solid rgba(12,32,22,.08);
      background: rgba(11,27,20,.02);
      border-radius:16px;
      padding:14px 12px;
      min-height: 118px;
      display:flex;
      flex-direction:column;
      justify-content:space-between; /* ✅ це дає "текст внизу" */
      align-items:center;
      text-align:center;
    }
    .stat-val{
      font-weight:900;
      font-size:20px;
      margin-top:6px;
      line-height:1.05;
    }
    .stat-lbl{
      font-weight:800;
      opacity:.7;
      font-size:13px;
      margin-bottom:6px;
      line-height:1.1;
    }

    /* Кнопки (залишив в ряд як у тебе, трохи більші) */
    .progress-actions{
      margin-top:18px;
      display:flex;
      gap:14px;
      align-items:center;
      justify-content:space-between;
      flex:0 0 auto;
    }
    .progress-actions .btn{
      flex:1 1 0;
      width:auto;
      justify-content:center;
      text-align:center;
      padding: 16px 18px;
      font-size: 16px;
      border-radius: 999px;
    }

    @media (max-width: 560px){
      .progress-actions{flex-direction:column;}
      .progress-actions .btn{width:100%;}
      .progress-card{width:100%;}
      .stats-grid{max-width: 100%;}
    }
  </style>
</head>

<body>

<header class="header">
  <div class="container header__inner">
    <a class="brand" href="/" aria-label="На головну">
      <img class="brand__logo" src="/assets/img/logo.svg" alt="ProstoPDR" />
    </a>

    <div class="header__actions">
      <button class="userpill" type="button" data-user-menu-btn aria-label="Профіль">
        <span class="userpill__avatar">🎓</span>
        <span class="userpill__meta">
          <span class="userpill__name"><?= h($nameFirst) ?></span>
          <span class="userpill__email"><?= h($email) ?></span>
        </span>
        <span class="userpill__chev">▾</span>
      </button>

      <div class="usermenu" data-user-menu>
        <div class="usermenu__head">
          <div class="usermenu__avatar">🎓</div>
          <div class="usermenu__text">
            <div class="usermenu__name"><?= h($nameRaw) ?></div>
            <div class="usermenu__email"><?= h($email) ?></div>
          </div>
        </div>

        <a class="usermenu__item" href="/account"><span class="usermenu__icon">👤</span> Кабінет</a>
        <a class="usermenu__item" href="#"><span class="usermenu__icon">🧑‍</span> Викладач</a>
        <a class="usermenu__item" href="/account?tab=subscriptions"><span class="usermenu__icon">💳</span> Мої підписки</a>
        <a class="usermenu__item" href="/"><span class="usermenu__icon">🏠</span> На головну</a>
        <a class="usermenu__item usermenu__item--danger" href="/logout"><span class="usermenu__icon">↩</span> Вийти</a>
      </div>

      <button class="burger" type="button" aria-label="Меню" data-burger>
        <span></span><span></span><span></span>
      </button>
    </div>
  </div>

  <div class="mobile" data-mobile>
    <div class="mobile__top">
      <div class="mobile__title">Меню</div>
      <button class="mobile__close" type="button" aria-label="Закрити" data-mobile-close>✕</button>
    </div>

    <div class="mobile__inner">
      <a class="mobile__link" href="/account">Кабінет</a>
      <a class="mobile__link" href="/account?tab=subscriptions">Мої підписки</a>
      <a class="mobile__link" href="/account/tests.php">Тести</a>
      <a class="mobile__link" href="/account/tests.php?mode=exam">Іспит</a>
      <a class="mobile__link" href="/account/tests.php?mode=trainer">Тренажер</a>
      <a class="mobile__link" href="/">На головну</a>

      <div class="mobile__divider"></div>

      <a class="btn btn--primary mobile__btn" href="/logout">Вийти</a>
    </div>
  </div>
</header>

<main class="section section--soft" style="padding-top:46px;">
  <div class="container">
    <h2 class="h2">Кабінет</h2>
    <p class="lead"></p>

    <div class="account-tabs">
      <a class="account-tab <?= $tab==='dashboard'?'is-active':''; ?>" href="/account?tab=dashboard">Кабінет</a>
      <a class="account-tab <?= $tab==='subscriptions'?'is-active':''; ?>" href="/account?tab=subscriptions">Мої підписки</a>
      <a class="account-tab <?= $tab==='tests'?'is-active':''; ?>" href="/account/tests.php">Тести</a>
      <a class="account-tab <?= $tab==='exam'?'is-active':''; ?>" href="/account/tests.php?mode=exam">Іспит</a>
      <a class="account-tab <?= $tab==='trainer'?'is-active':''; ?>" href="/account/tests.php?mode=trainer">Тренажер</a>
    </div>

    <?php if ($tab === 'dashboard'): ?>

      <div class="dash-top">

        <!-- PRICING -->
        <div class="dash-left">
          <div class="account-block" id="pricing">
            <h3 class="h3">Обрати тариф</h3>

            <div class="pricing pricing--account">
              <article class="plan plan--basic" id="planCard">
                <h3 class="plan__title">Базовий план<br/>підписка</h3>
                <p class="plan__desc">
                  Доступ до тестів ПДР, режиму «іспит», пояснень та статистики. Підписку можна скасувати у будь-який момент.
                </p>

                <div class="plan__price">
                  <span class="plan__amount">699,00 грн</span><span class="plan__period">/міс</span>
                </div>

                <div class="plan__banner">
                  <span class="dot dot--ok">✓</span>
                  Підписка поновлюється автоматично та діє до кінця оплаченого періоду. Доступ одразу після оплати.
                </div>

                <ul class="plan__list">
                  <li>Тести ПДР з поясненнями</li>
                  <li>Режим «іспит» з таймером</li>
                  <li>Повторення помилок та «слабкі теми»</li>
                  <li>Статистика прогресу по днях</li>
                  <li>Доступ з телефону/ПК у будь-який час</li>
                  <li>Нотатки до питань та тем</li>
                </ul>

                <div class="plan__cta-row">
                  <a class="btn btn--ghost plan__cta" href="/demo">Отримати 3 дні безкоштовно</a>
                  <a class="btn btn--primary plan__cta" href="/checkout?plan=basic">Обрати</a>
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
                <div class="stat-lbl">Всього питань</div>
              </div>
              <div class="stat">
                <div class="stat-val"><?= (int)$passedTestsCount ?> / <?= (int)$totalTests ?></div>
                <div class="stat-lbl">Тести пройдено</div>
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
              Питання по темах • пояснення
            </div>
            <div style="height:10px"></div>
            <?php if ($hasAccess): ?>
              <a class="btn btn--ghost" href="/account/tests.php">Відкрити →</a>
            <?php else: ?>
              <span style="color:rgba(11,27,20,.55);font-weight:800;">Відкрити →</span>
            <?php endif; ?>
          </div>

          <div class="sub-card study-card <?= !$hasAccess ? 'is-locked' : ''; ?>" style="background:#fff;">
            <?php if (!$hasAccess): ?><div class="study-card__lock" title="Доступ закрито">🔒</div><?php endif; ?>
            <div class="study-card__title" style="font-weight:900;margin-bottom:6px;">Іспит</div>
            <div style="color:rgba(11,27,20,.65);font-weight:700;line-height:1.4;">
              Таймер • ліміт помилок • 1 спроба
            </div>
            <div style="height:10px"></div>
            <?php if ($hasAccess): ?>
              <a class="btn btn--ghost" href="/account/tests.php?mode=exam">Почати →</a>
            <?php else: ?>
              <span style="color:rgba(11,27,20,.55);font-weight:800;">Почати →</span>
            <?php endif; ?>
          </div>

          <div class="sub-card study-card <?= !$hasAccess ? 'is-locked' : ''; ?>" style="background:#fff;">
            <?php if (!$hasAccess): ?><div class="study-card__lock" title="Доступ закрито">🔒</div><?php endif; ?>
            <div class="study-card__title" style="font-weight:900;margin-bottom:6px;">Тренажер</div>
            <div style="color:rgba(11,27,20,.65);font-weight:700;line-height:1.4;">
              Повтор помилок • мікс питань
            </div>
            <div style="height:10px"></div>
            <?php if ($hasAccess): ?>
              <a class="btn btn--ghost" href="/account/tests.php?mode=trainer">Тренуватись →</a>
            <?php else: ?>
              <span style="color:rgba(11,27,20,.55);font-weight:800;">Тренуватись →</span>
            <?php endif; ?>
          </div>
        </div>

        <?php if (!$hasAccess): ?>
          <div class="sub-card" style="margin-top:14px;">
            <b>Щоб відкрити тести/іспит/тренажер — обери тариф вище.</b>
          </div>
        <?php endif; ?>

        <div class="dash-split">

          <div class="account-card">
            <h3 class="h3">Працювати над помилками</h3>
            <p class="lead">
              Зібрані помилки з усіх тестів: <b><?= (int)$mistakesCount; ?></b>
            </p>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;">
              <a class="btn btn--primary" href="<?= $hasAccess ? '/account/tests.php?mode=trainer&mistakes=1' : '/account?tab=dashboard#pricing'; ?>">
                Повтор помилок →
              </a>
              <a class="btn btn--ghost" href="<?= $hasAccess ? '/account/tests.php' : '/account?tab=dashboard#pricing'; ?>">
                До тестів
              </a>
            </div>
            <?php if ($mistakesCount === 0): ?>
              <div class="lock-note" style="margin-top:12px;">
                Поки що помилок немає. Вони з’являться після проходження тестів (коли відповіси неправильно).
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