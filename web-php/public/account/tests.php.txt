<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
  @session_start();
}

require __DIR__ . '/../../src/bootstrap.php';

$uid = auth_user_id();
if (!$uid) {
  header('Location: /login', true, 302);
  exit;
}

$hasAccess = !empty($_SESSION['has_access']);
if (!$hasAccess) {
  http_response_code(200);
  ?>
  <!doctype html>
  <html lang="uk">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Доступ обмежено — ProstoPDR</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Unbounded:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/style.css?v=4" />
  </head>
  <body>
    <main class="section section--soft" style="padding-top:46px;">
      <div class="container">
        <div class="account-card">
          <h2 class="h2">Доступ обмежено</h2>
          <p class="lead">Щоб відкрити тести, тренажер та іспит — активуй підписку.</p>

          <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;">
            <a class="btn btn--primary" href="/account/index.php?tab=dashboard#pricing">Обрати тариф</a>
            <a class="btn btn--ghost" href="/account/index.php">В кабінет</a>
          </div>
        </div>
      </div>
    </main>
  </body>
  </html>
  <?php
  exit;
}

$mode = (string)($_GET['mode'] ?? 'tests'); // tests | exam | trainer
$mode = in_array($mode, ['tests','exam','trainer'], true) ? $mode : 'tests';
$mistakes = !empty($_GET['mistakes']); // trainer special: repeat mistakes

// НАЛАШТУВАННЯ (як ти просив)
const EXAM_QUESTIONS = 40;
const EXAM_TIME_SEC  = 40 * 60; // 40 хв
const EXAM_MISTAKES  = 3;

const TRAINER_QUESTIONS = 40; // 40 питань, без часу

// ✅ Обрізка до цієї теми включно
const CUTOFF_TOPIC = 'ДОДАТКОВІ ПИТАННЯ ЩОДО КАТЕГОРІЙ В1, В (БУДОВА І ТЕРМІНИ)';

$DATA_DIR = __DIR__ . '/../data';
$testsFile = $DATA_DIR . '/tests_export.json';
$questionsFile = $DATA_DIR . '/questions_export.json';

if (!is_file($testsFile) || !is_file($questionsFile)) {
  http_response_code(500);
  echo "Не знайдено data-файли. Очікую: {$testsFile} та {$questionsFile}";
  exit;
}

// load JSON with BOM-strip
$testsRaw = (string)file_get_contents($testsFile);
if (strncmp($testsRaw, "\xEF\xBB\xBF", 3) === 0) $testsRaw = substr($testsRaw, 3);
$testsAll = json_decode($testsRaw, true);
if (!is_array($testsAll)) $testsAll = [];

$questionsRaw = (string)file_get_contents($questionsFile);
if (strncmp($questionsRaw, "\xEF\xBB\xBF", 3) === 0) $questionsRaw = substr($questionsRaw, 3);
$questions = json_decode($questionsRaw, true);
if (!is_array($questions)) $questions = [];

/**
 * ✅ Прогрес користувача (галочки біля складених тестів)
 * Зберігається у public/storage/progress.json (як у quiz.php)
 */
function progress_path(): string {
  return dirname(__DIR__, 2) . '/storage/progress.json'; // web-php/public/storage/progress.json
}

function progress_load(): array {
  $p = progress_path();
  if (!is_file($p)) return ['users' => []];
  $raw = file_get_contents($p);
  if ($raw === false) return ['users' => []];
  if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);
  $data = json_decode($raw, true);
  if (!is_array($data)) return ['users' => []];
  if (!isset($data['users']) || !is_array($data['users'])) $data['users'] = [];
  return $data;
}

$progress = progress_load();
$userProgress = $progress['users'][(string)$uid] ?? [];
if (!is_array($userProgress)) $userProgress = [];
$passedTests = $userProgress['passed_tests'] ?? [];
if (!is_array($passedTests)) $passedTests = [];

// quick map
$qMap = [];
foreach ($questions as $q) {
  if (is_array($q) && isset($q['id'])) $qMap[(int)$q['id']] = $q;
}

/**
 * ✅ Обрізаємо список тестів/тем:
 * залишаємо все по порядку ДО і ВКЛЮЧНО теми CUTOFF_TOPIC.
 * Якщо тему не знайдено — залишаємо весь файл (щоб не зламати сайт).
 */
$tests = [];
$cutFound = false;
foreach ($testsAll as $t) {
  if (!is_array($t)) continue;
  $tests[] = $t;

  $topic = (string)($t['topic'] ?? '');
  if ($topic === CUTOFF_TOPIC) {
    $cutFound = true;
    break;
  }
}
if (!$cutFound) {
  $tests = $testsAll;
}

// group tests by topic (ТВОЯ логіка — лишив)
$topics = [];
foreach ($tests as $t) {
  if (!is_array($t)) continue;
  $topic = (string)($t['topic'] ?? 'Без теми');
  $topics[$topic][] = $t;
}

// ===== ДОДАНО: підготовка пулів питань по темах для іспитів/тренажера =====
$topicPools = [];        // topic => unique qids (тільки з type=test)
$topicPoolsCount = [];   // topic => count
$allPool = [];           // all qids (АЛЕ тільки з дозволених тестів, щоб "мікс" не брав зайве)

$allowedQidSet = []; // qid => true (лише з тестів до cutoff)

// ===== ДОДАНО: айді тестів по темах для ЛОГІКИ ВІДКРИТТЯ ІСПИТІВ =====
$topicTestIds = []; // topic => [testId1, testId2, ...]
$allTestIds   = []; // всі test_id з усіх тем

foreach ($topics as $topicName => $items) {
  $set = [];
  $tids = [];

  foreach ($items as $t) {
    if (!is_array($t)) continue;
    if ((string)($t['type'] ?? 'test') !== 'test') continue;

    // збираємо питання
    $qids = $t['question_ids'] ?? [];
    if (is_array($qids)) {
      foreach ($qids as $qid) {
        $qid = (int)$qid;
        if ($qid > 0) {
          $set[$qid] = true;
          $allowedQidSet[$qid] = true;
        }
      }
    }

    // збираємо ID тестів (для unlock)
    $tid = (int)($t['id'] ?? 0);
    if ($tid > 0) {
      $tids[] = $tid;
      $allTestIds[$tid] = true;
    }
  }

  $pool = array_keys($set);
  sort($pool);
  $topicPools[$topicName] = $pool;
  $topicPoolsCount[$topicName] = count($pool);

  $tids = array_values(array_unique($tids));
  sort($tids);
  $topicTestIds[$topicName] = $tids;
}

// allPool тільки з дозволених qid і тільки якщо вони є в questions_export.json
foreach ($allowedQidSet as $qid => $_) {
  $qid = (int)$qid;
  if ($qid > 0 && isset($qMap[$qid])) $allPool[] = $qid;
}
sort($allPool);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// ===== ДОДАНО: функція перевірки "складені всі тести" =====
function all_tests_passed(array $testIds, array $passedTests): bool {
  if (empty($testIds)) return false; // якщо в темі нема тестів — не відкривати
  foreach ($testIds as $tid) {
    $tid = (int)$tid;
    if ($tid <= 0) continue;
    if (empty($passedTests[(string)$tid])) return false;
  }
  return true;
}

$title = 'Підготовчі запитання до іспиту';
if ($mode === 'exam') $title = 'Іспит';
if ($mode === 'trainer') $title = 'Тренажер';

$csrf = csrf_token();
?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?php echo h($title); ?> — ProstoPDR</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Unbounded:wght@500;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/style.css?v=4" />
</head>
<body>

<header class="header">
  <div class="container header__inner">
    <a class="brand" href="/account/index.php" aria-label="Назад в кабінет">
      <img class="brand__logo" src="/assets/img/logo.svg" alt="ProstoPDR" />
    </a>
    <div class="header__actions">
      <a class="btn btn--ghost" href="/account/index.php">Назад</a>
    </div>
  </div>
</header>

<main class="section section--soft" style="padding-top:46px;">
  <div class="container">
    <h2 class="h2"><?php echo h($title); ?></h2>

    <!-- ✅ описовий текст прибраний як ти просив -->

    <div class="tests-topbar">
      <a class="account-tab <?php echo $mode==='tests'?'is-active':''; ?>" href="/account/tests.php">Тести</a>
      <a class="account-tab <?php echo $mode==='exam'?'is-active':''; ?>" href="/account/tests.php?mode=exam">Іспит</a>
      <a class="account-tab <?php echo $mode==='trainer'?'is-active':''; ?>" href="/account/tests.php?mode=trainer">Тренажер</a>
    </div>

    <?php if ($mode === 'exam'): ?>

      <!-- =========================
           ІСПИТИ (ПО ТЕМАХ + МІКС)
           ЛОГІКА: іспит по темі відкривається тільки якщо складені ВСІ тести цієї теми
           ========================= -->

      <?php
        $mixedUnlocked = all_tests_passed(array_keys($allTestIds), $passedTests);
      ?>

      <div class="account-card" style="margin-top:12px;">
        <h3 class="h3" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
          <span>Змішаний іспит (всі теми)</span>
          <?php if (!$mixedUnlocked): ?>
            <span style="display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;background:#f3f4f6;color:#111827;font-weight:800;font-size:12px;line-height:1;">
              <span aria-hidden="true" style="font-size:14px;">🔒</span>
              <span>Заблоковано</span>
            </span>
          <?php endif; ?>
        </h3>

        <p class="lead" style="margin-top:6px;">
          40 питань • 40 хв • 3 помилки • випадково з усіх тем
        </p>

        <?php if (!$mixedUnlocked): ?>
          <p class="lead" style="margin-top:8px;">
            Щоб відкрити змішаний іспит — склади <b>усі тести по всіх темах</b>.
          </p>
        <?php endif; ?>

        <div style="margin-top:12px; display:flex; gap:12px; flex-wrap:wrap;">
          <form method="post" action="/account/quiz.php">
            <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
            <input type="hidden" name="action" value="start">
            <input type="hidden" name="mode" value="exam_mix">
            <input type="hidden" name="seed" value="<?php echo (int)random_int(1, 1000000000); ?>">
            <button class="btn btn--primary" type="submit" <?php echo $mixedUnlocked ? '' : 'disabled style="opacity:.55;cursor:not-allowed;"'; ?>>
              <?php echo $mixedUnlocked ? 'Почати змішаний іспит →' : 'Почати (заблоковано)'; ?>
            </button>
          </form>
        </div>
      </div>

      <?php foreach ($topicPools as $topicName => $pool): ?>
        <?php
          $total = count($pool);
          if ($total <= 0) continue;

          $parts = (int)ceil($total / EXAM_QUESTIONS);

          $topicUnlocked = all_tests_passed($topicTestIds[$topicName] ?? [], $passedTests);
        ?>

        <div class="topic-block" style="margin-top:14px;">
          <div class="topic-block__head" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
            <h3 class="h3" style="margin:0;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
              <span><?php echo h($topicName); ?></span>
              <?php if (!$topicUnlocked): ?>
                <span style="display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;background:#f3f4f6;color:#111827;font-weight:800;font-size:12px;line-height:1;">
                  <span aria-hidden="true" style="font-size:14px;">🔒</span>
                  <span>Заблоковано</span>
                </span>
              <?php endif; ?>
            </h3>
          </div>

          <?php if (!$topicUnlocked): ?>
            <div class="account-card" style="margin-top:10px;">
              <p class="lead" style="margin:0;">
                Щоб відкрити іспити по темі <b><?php echo h($topicName); ?></b> — склади <b>усі тести цієї теми</b>.
              </p>
            </div>
          <?php endif; ?>

          <div class="topic-tests">
            <?php for ($p = 1; $p <= $parts; $p++): ?>
              <?php
                $seed = (int)abs(crc32($topicName . '|' . $p . '|exam'));
              ?>
              <div class="test-card">
                <div class="test-card__left">
                  <div class="test-card__title"><?php echo h("Іспит {$p} (по темі)"); ?></div>
                  <div class="test-card__meta">
                    <span>Питань: <b><?php echo (int)EXAM_QUESTIONS; ?></b></span>
                    <span>Час: <b><?php echo (int)round(EXAM_TIME_SEC/60); ?> хв</b></span>
                    <span>Помилок: <b><?php echo (int)EXAM_MISTAKES; ?></b></span>
                  </div>
                </div>
                <div class="test-card__right">
                  <form method="post" action="/account/quiz.php">
                    <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                    <input type="hidden" name="action" value="start">
                    <input type="hidden" name="mode" value="exam_topic">
                    <input type="hidden" name="topic" value="<?php echo h($topicName); ?>">
                    <input type="hidden" name="part" value="<?php echo (int)$p; ?>">
                    <input type="hidden" name="seed" value="<?php echo (int)$seed; ?>">
                    <button class="btn btn--primary" type="submit" <?php echo $topicUnlocked ? '' : 'disabled style="opacity:.55;cursor:not-allowed;"'; ?>>
                      <?php echo $topicUnlocked ? 'Почати' : 'Почати (🔒)'; ?>
                    </button>
                  </form>
                </div>
              </div>
            <?php endfor; ?>
          </div>
        </div>

      <?php endforeach; ?>


    <?php elseif ($mode === 'trainer'): ?>

      <!-- =========================
           ТРЕНАЖЕР (ПО ТЕМАХ + МІКС + ПОМИЛКИ)
           ========================= -->

      <div class="account-card" style="margin-top:12px;">
        <h3 class="h3">Тренажер (мікс)</h3>
        <p class="lead" style="margin-top:6px;">
          40 питань • без таймера • випадково з усіх тем • пояснення по всіх питаннях
        </p>

        <div style="margin-top:12px; display:flex; gap:12px; flex-wrap:wrap;">
          <form method="post" action="/account/quiz.php">
            <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
            <input type="hidden" name="action" value="start">
            <input type="hidden" name="mode" value="trainer_mix">
            <input type="hidden" name="seed" value="<?php echo (int)random_int(1, 1000000000); ?>">
            <button class="btn btn--primary" type="submit">Почати мікс →</button>
          </form>

          <a class="btn btn--ghost" href="/account/tests.php?mode=trainer&mistakes=1">Повтор помилок →</a>
        </div>
      </div>

      <?php if ($mistakes): ?>
        <div class="account-card" style="margin-top:12px;">
          <h3 class="h3">Повтор помилок</h3>
          <p class="lead" style="margin-top:6px;">
            Лише питання, де були помилки
          </p>

          <div style="margin-top:12px; display:flex; gap:12px; flex-wrap:wrap;">
            <form method="post" action="/account/quiz.php">
              <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
              <input type="hidden" name="action" value="start">
              <input type="hidden" name="mode" value="trainer">
              <input type="hidden" name="mistakes_only" value="1">
              <button class="btn btn--primary" type="submit">Почати повтор →</button>
            </form>
            <a class="btn btn--ghost" href="/account/tests.php?mode=trainer">Назад до тренажера</a>
          </div>
        </div>
      <?php endif; ?>

      <?php foreach ($topicPools as $topicName => $pool): ?>
        <?php if (count($pool) <= 0) continue; ?>
        <div class="test-card" style="margin-top:12px;">
          <div class="test-card__left">
            <div class="test-card__title"><?php echo h($topicName); ?></div>
            <div class="test-card__meta">
              <span>Питань: <b><?php echo (int)TRAINER_QUESTIONS; ?></b></span>
              <span>Час: <b>без таймера</b></span>
            </div>
          </div>
          <div class="test-card__right">
            <form method="post" action="/account/quiz.php">
              <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
              <input type="hidden" name="action" value="start">
              <input type="hidden" name="mode" value="trainer_topic">
              <input type="hidden" name="topic" value="<?php echo h($topicName); ?>">
              <input type="hidden" name="seed" value="<?php echo (int)random_int(1, 1000000000); ?>">
              <button class="btn btn--primary" type="submit">Тренуватись</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>


    <?php else: ?>

      <!-- =========================
           ТЕСТИ (ТВОЯ ОРИГІНАЛЬНА ВЕРСТКА)
           ========================= -->

      <?php foreach ($topics as $topicName => $items): ?>
        <div class="topic-block">
          <div class="topic-block__head">
            <h3 class="h3"><?php echo h($topicName); ?></h3>
          </div>

          <div class="topic-tests">
            <?php foreach ($items as $t): ?>
              <?php if (($t['type'] ?? 'test') !== 'test') continue; ?>
              <?php
                $rawIds = is_array($t['question_ids'] ?? null) ? $t['question_ids'] : [];
                $qCount = count($rawIds);

                $time = (int)($t['time_limit_sec'] ?? 1200);
                if ($time <= 0) $time = 1200;

                // як ти хотів — 3 помилки
                $mist = 3;
              ?>
              <div class="test-card">
                <div class="test-card__left">
                  <?php
                    $tid = (int)($t['id'] ?? 0);
                    $isPassed = ($tid > 0) && !empty($passedTests[(string)$tid]);
                  ?>
                  <div class="test-card__title" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <span><?php echo h((string)($t['title'] ?? 'Тест')); ?></span>
                    <?php if ($isPassed): ?>
                      <span style="display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;background:#e9f7ef;color:#0a7a3d;font-weight:800;font-size:12px;line-height:1;">
                        <span aria-hidden="true" style="font-size:14px;">✅</span>
                        <span>Складено</span>
                      </span>
                    <?php endif; ?>
                  </div>
                  <div class="test-card__meta">
                    <span>Питань: <b><?php echo (int)$qCount; ?></b></span>
                    <span>Час: <b><?php echo (int)round($time/60); ?> хв</b></span>
                    <span>Помилок: <b><?php echo (int)$mist; ?></b></span>
                  </div>
                </div>
                <div class="test-card__right">
                  <form method="post" action="/account/quiz.php">
                    <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                    <input type="hidden" name="action" value="start">
                    <input type="hidden" name="mode" value="test">
                    <input type="hidden" name="test_id" value="<?php echo (int)($t['id'] ?? 0); ?>">
                    <button class="btn btn--primary" type="submit">Почати</button>
                  </form>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>

    <?php endif; ?>

  </div>
</main>

</body>
</html>