<?php
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

/**
 * ProstoPDR / public/account/quiz.php
 * JSON: public/data/questions_export.json, public/data/tests_export.json
 *
 * FIXES / ADD:
 * ✅ exam_topic / exam_mix / trainer_topic / trainer_mix
 * ✅ Exam: 40 questions, 40 minutes, 3 mistakes
 * ✅ Trainer: 40 questions, unlimited time, unlimited mistakes
 * ✅ After answering: DO NOT auto-next (show explain + "Далі")
 * ✅ Cut everything AFTER cutoff topic:
 *    "ДОДАТКОВІ ПИТАННЯ ЩОДО КАТЕГОРІЙ В1, В (БУДОВА І ТЕРМІНИ)"
 */

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function json_load_array(string $absPath): array {
    if (!is_file($absPath)) throw new RuntimeException("JSON file not found: {$absPath}");
    $raw = file_get_contents($absPath);
    if ($raw === false) throw new RuntimeException("Failed to read JSON file: {$absPath}");

    // Strip UTF-8 BOM if present
    if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $err = json_last_error_msg();
        $size = strlen($raw);
        throw new RuntimeException("Invalid JSON in {$absPath}. json_decode error: {$err}. bytes={$size}");
    }
    return $data;
}

function questions_map(array $questions): array {
    $map = [];
    foreach ($questions as $q) {
        if (!is_array($q)) continue;
        if (!isset($q['id'])) continue;
        $id = (int)$q['id'];
        if ($id <= 0) continue;

        if (!isset($q['question']) || !is_string($q['question']) || trim($q['question']) === '') continue;
        if (!isset($q['options']) || !is_array($q['options']) || count($q['options']) < 2) continue;
        if (!isset($q['correct'])) continue;

        $correct = (int)$q['correct'];
        if ($correct < 1 || $correct > count($q['options'])) continue;

        if (!array_key_exists('explain', $q) || !is_string($q['explain'])) $q['explain'] = '';
        if (!array_key_exists('image', $q)) $q['image'] = null;
        if ($q['image'] === '') $q['image'] = null;

        $opts = [];
        foreach ($q['options'] as $o) $opts[] = is_string($o) ? $o : (string)$o;
        $q['options'] = $opts;

        $map[$id] = $q;
    }
    return $map;
}

function tests_map(array $tests): array {
    $map = [];
    foreach ($tests as $t) {
        if (!is_array($t)) continue;
        if (!isset($t['id'])) continue;
        $id = (int)$t['id'];
        if ($id <= 0) continue;
        $map[$id] = $t;
    }
    return $map;
}

function quiz_redirect(string $url): void {
    redirect($url);
    exit;
}

function quiz_abort(string $title, array $debug = []): void {
    http_response_code(200);
    $csrf = csrf_token();
    ?>
    <!doctype html>
    <html lang="uk">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= h($title) ?></title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Unbounded:wght@500;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="/assets/css/style.css?v=4">
        <style>
            .pp-wrap{max-width:980px;margin:24px auto;padding:0 16px}
            .pp-card{background:#fff;border-radius:16px;padding:18px;box-shadow:0 8px 30px rgba(0,0,0,0.06)}
            .pp-title{font-size:20px;font-weight:800;margin:0 0 12px}
            .pp-sub{opacity:.8;margin:0 0 12px}
            pre{white-space:pre-wrap;background:#0b1020;color:#d9e3ff;padding:14px;border-radius:12px;overflow:auto}
            .pp-actions{margin-top:14px;display:flex;gap:10px;flex-wrap:wrap}
            .pp-btn{display:inline-block;padding:10px 14px;border-radius:12px;background:#111;color:#fff;text-decoration:none;border:none}
            .pp-btn2{display:inline-block;padding:10px 14px;border-radius:12px;background:#f1f3f7;color:#111;text-decoration:none}
        </style>
    </head>
    <body>
    <div class="pp-wrap">
        <div class="pp-card">
            <h1 class="pp-title"><?= h($title) ?></h1>
            <p class="pp-sub">Перевір діагностику нижче — вона покаже точну причину.</p>

            <?php if (!empty($debug)): ?>
                <pre><?= h(json_encode($debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
            <?php endif; ?>

            <div class="pp-actions">
                <a class="pp-btn2" href="/account/tests.php">← Назад до тестів</a>
                <form method="post" action="/account/quiz.php" style="margin:0">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="reset">
                    <button class="pp-btn" type="submit">Скинути сесію тесту</button>
                </form>
            </div>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

/** -------- Progress store (JSON, no SQL) -------- */
function progress_path(): string {
    return dirname(__DIR__, 2) . '/storage/progress.json'; // web-php/storage/progress.json
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

function progress_save(array $data): void {
    $p = progress_path();
    $dir = dirname($p);
    if (!is_dir($dir)) @mkdir($dir, 0777, true);

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) return;

    $tmp = $p . '.tmp';
    file_put_contents($tmp, $json);
    @rename($tmp, $p);
}

function progress_user_get(string $uid): array {
    $data = progress_load();
    $u = $data['users'][$uid] ?? null;
    if (!is_array($u)) {
        $u = [
            'passed_tests' => [],  // [test_id => true]
            'passed_items' => [],  // ✅ NEW: [key => ['title' => string, 'at' => ISO8601]]
            'mistakes' => [],      // [test_id => [qid...]]
            'updated_at' => date('c'),
        ];
    }
    if (!isset($u['passed_tests']) || !is_array($u['passed_tests'])) $u['passed_tests'] = [];
    if (!isset($u['passed_items']) || !is_array($u['passed_items'])) $u['passed_items'] = [];
    if (!isset($u['mistakes']) || !is_array($u['mistakes'])) $u['mistakes'] = [];
    return $u;
}

function progress_user_set(string $uid, array $u): void {
    $data = progress_load();
    if (!isset($data['users']) || !is_array($data['users'])) $data['users'] = [];
    $u['updated_at'] = date('c');
    $data['users'][$uid] = $u;
    progress_save($data);
}

function progress_add_mistakes(string $uid, int $testId, array $qids): void {
    $u = progress_user_get($uid);
    $k = (string)$testId;
    $arr = $u['mistakes'][$k] ?? [];
    if (!is_array($arr)) $arr = [];
    $set = [];
    foreach ($arr as $x) $set[(string)(int)$x] = true;
    foreach ($qids as $x) $set[(string)(int)$x] = true;

    $out = [];
    foreach (array_keys($set) as $idStr) {
        $id = (int)$idStr;
        if ($id > 0) $out[] = $id;
    }
    sort($out);
    $u['mistakes'][$k] = $out;
    progress_user_set($uid, $u);
}

function progress_mark_passed(string $uid, int $testId): void {
    $u = progress_user_get($uid);
    $u['passed_tests'][(string)$testId] = true;
    progress_user_set($uid, $u);
}


function progress_mark_passed_item(string $uid, string $key, string $title): void {
    $u = progress_user_get($uid);
    $u['passed_items'][$key] = [
        'title' => $title,
        'at' => date('c'),
    ];
    progress_user_set($uid, $u);
}

function passed_item_key(array $quiz): string {
    $mode = (string)($quiz['mode'] ?? 'unknown');
    $topic = (string)($quiz['topic'] ?? '');
    $topicReq = (string)($quiz['topic_req'] ?? '');
    $part = (int)($quiz['part'] ?? 1);
    $seed = (string)($quiz['seed'] ?? '');
    $mist = !empty($quiz['mistakes_only']) ? 'mistakes' : 'all';
    $testId = (int)($quiz['test_id'] ?? 0);

    // Stable key so we can show "✅ Складено" for exam/trainer attempts
    return $mode . '|' . $mist . '|topic=' . $topic . '|topic_req=' . $topicReq . '|part=' . $part . '|seed=' . $seed . '|testid=' . $testId;
}


function progress_all_mistakes_ids(string $uid): array {
    $u = progress_user_get($uid);
    $out = [];
    foreach ($u['mistakes'] as $list) {
        if (!is_array($list)) continue;
        foreach ($list as $qid) {
            $qid = (int)$qid;
            if ($qid > 0) $out[$qid] = true;
        }
    }
    $ids = array_keys($out);
    sort($ids);
    return $ids;
}

/** -------- Auth -------- */
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

/** -------- Load data -------- */
$dataDir = realpath(__DIR__ . '/../data');
if ($dataDir === false) {
    quiz_abort('Не знайдено папку data', ['expected' => __DIR__ . '/../data']);
}

$qFile = realpath($dataDir . '/questions_export.json');
$tFile = realpath($dataDir . '/tests_export.json');

if ($qFile === false || $tFile === false) {
    quiz_abort('Не знайдено data-файли', [
        'questions_expected' => __DIR__ . '/../data/questions_export.json',
        'tests_expected' => __DIR__ . '/../data/tests_export.json',
    ]);
}

try {
    $qMapFull = questions_map(json_load_array($qFile));
} catch (Throwable $e) {
    quiz_abort('Помилка читання questions_export.json', [
        'error' => $e->getMessage(),
        'questions_file' => $qFile,
    ]);
}

try {
    $testsListFull = json_load_array($tFile); // keep order
} catch (Throwable $e) {
    quiz_abort('Помилка читання tests_export.json', [
        'error' => $e->getMessage(),
        'tests_file' => $tFile,
    ]);
}

/** ===== CUT OFF AFTER TOPIC ===== */
const CUTOFF_TOPIC = 'ДОДАТКОВІ ПИТАННЯ ЩОДО КАТЕГОРІЙ В1, В (БУДОВА І ТЕРМІНИ)';

/**
 * Keep tests only up to cutoff topic (inclusive) in original order.
 */
$testsList = [];
$cutFound = false;
foreach ($testsListFull as $t) {
    if (!is_array($t)) continue;
    $testsList[] = $t;
    $topic = (string)($t['topic'] ?? '');
    if ($topic === CUTOFF_TOPIC) {
        $cutFound = true;
        break;
    }
}
// if cutoff not found: keep all (safe fallback)
if (!$cutFound) {
    $testsList = $testsListFull;
}

/** Build topic pools from allowed tests (type=test only) */
$topicPools = [];   // topic => [qids...]
$allowedQidsSet = []; // qid => true

foreach ($testsList as $t) {
    if (!is_array($t)) continue;
    if ((string)($t['type'] ?? 'test') !== 'test') continue;

    $topic = (string)($t['topic'] ?? 'Без теми');
    $qids = $t['question_ids'] ?? [];
    if (!is_array($qids)) $qids = [];

    foreach ($qids as $id) {
        $qid = (int)$id;
        if ($qid <= 0) continue;
        $allowedQidsSet[$qid] = true;
        $topicPools[$topic][$qid] = true;
    }
}

/** Filter qMap by allowed question ids (so mix/exam do not include beyond cutoff) */
$qMap = [];
foreach ($allowedQidsSet as $qid => $_) {
    if (isset($qMapFull[$qid])) $qMap[$qid] = $qMapFull[$qid];
}

// normalize topicPools to sorted arrays
$topicPoolsOut = [];
foreach ($topicPools as $topic => $set) {
    $ids = array_map('intval', array_keys($set));
    sort($ids);
    $topicPoolsOut[$topic] = $ids;
}
$topicPools = $topicPoolsOut;

// tests map only from allowed tests
$tMap = tests_map($testsList);

/** -------- Helpers -------- */
function quiz_session_is_valid(array $quiz): bool {
    if (!isset($quiz['q_ids']) || !is_array($quiz['q_ids']) || count($quiz['q_ids']) < 1) return false;
    if (!isset($quiz['idx'])) return false;
    if (!isset($quiz['total'])) return false;
    return true;
}

function quiz_count_mistakes(array $quiz): int {
    $answers = $quiz['answers'] ?? [];
    if (!is_array($answers)) return 0;
    $m = 0;
    foreach ($answers as $a) {
        if (is_array($a) && isset($a['is_correct']) && $a['is_correct'] === false) $m++;
    }
    return $m;
}

function format_mmss(int $sec): string {
    if ($sec < 0) $sec = 0;
    $m = intdiv($sec, 60);
    $s = $sec % 60;
    return sprintf('%d:%02d', $m, $s);
}

/** Utility: sample N ids deterministically by seed */
function sample_ids(array $ids, int $n, int $seed): array {
    $ids = array_values($ids);
    if ($seed === 0) $seed = 777;
    mt_srand($seed);
    shuffle($ids);
    return array_slice($ids, 0, min($n, count($ids)));
}

/** -------- Actions -------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify((string)($_POST['csrf'] ?? null));

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'reset') {
        unset($_SESSION['quiz']);
        quiz_redirect('/account/tests.php');
    }

    if ($action === 'start') {
        // ✅ add new modes (case-insensitive) + do NOT silently downgrade to "test"
        $modeRaw = strtolower((string)($_POST['mode'] ?? 'test'));
        $allowedModes = ['test','exam','trainer','exam_topic','exam_mix','trainer_topic','trainer_mix'];
        if (!in_array($modeRaw, $allowedModes, true)) {
            quiz_abort('Невірний режим запуску', ['mode' => $modeRaw, 'allowed' => $allowedModes]);
        }
        $mode = $modeRaw;

        $testId = (int)($_POST['test_id'] ?? 0);
        $seed = (int)($_POST['seed'] ?? 0);
        $mistakesOnly = !empty($_POST['mistakes_only']);

        $topicReq = (string)($_POST['topic'] ?? '');
        $partReq  = (int)($_POST['part'] ?? 1);
        if ($partReq < 1) $partReq = 1;

        $title = 'Тест';
        $topic = '';
        $timeLimit = 1200;
        $maxMistakes = 3;

        $qIds = [];

        // ===== TEST (as was) =====
        if ($mode === 'test') {
            $t = $tMap[$testId] ?? null;
            if (!is_array($t)) {
                quiz_abort('Тест не знайдено (або обрізано за темами)', ['test_id' => $testId]);
            }

            $title = (string)($t['title'] ?? 'Тест');
            $topic = (string)($t['topic'] ?? '');

            $timeLimit = (int)($t['time_limit_sec'] ?? 1200);
            if ($timeLimit <= 0) $timeLimit = 1200;

            $maxMistakes = 3;

            $rawIds = $t['question_ids'] ?? [];
            if (!is_array($rawIds)) $rawIds = [];

            $filtered = [];
            $missing = [];
            foreach ($rawIds as $id) {
                $qid = (int)$id;
                // additionally ensure qid is within cutoff set (qMap)
                if ($qid > 0 && isset($qMap[$qid])) $filtered[] = $qid;
                else $missing[] = $qid;
            }

            if (count($filtered) < 1) {
                quiz_abort('У тесті немає валідних питань (після обрізки)', [
                    'test_id' => $testId,
                    'title' => $title,
                    'missing_examples' => array_slice(array_values(array_filter($missing, fn($x)=> (int)$x>0)), 0, 20),
                ]);
            }

            $qIds = array_values($filtered);
        }

        // ===== EXAM MIX =====
        if ($mode === 'exam' || $mode === 'exam_mix') {
            $title = 'Іспит';
            $topic = ($mode === 'exam_mix') ? 'Змішаний іспит' : 'Контрольний іспит';
            $timeLimit = 40 * 60; // 40 хв
            $maxMistakes = 3;

            $all = array_keys($qMap);
            if (count($all) < 40) {
                quiz_abort('Недостатньо питань для іспиту', [
                    'total_questions_available' => count($all),
                    'need' => 40,
                ]);
            }

            if ($seed === 0) $seed = 777;
            $qIds = sample_ids($all, 40, $seed);
        }

        // ===== EXAM TOPIC (by topic + part) =====
        if ($mode === 'exam_topic') {
            $title = 'Іспит';
            $topic = $topicReq !== '' ? $topicReq : 'Іспит по темі';
            $timeLimit = 40 * 60; // 40 хв
            $maxMistakes = 3;

            if ($topicReq === '' || !isset($topicPools[$topicReq]) || !is_array($topicPools[$topicReq])) {
                quiz_abort('Невірна тема для іспиту', ['topic' => $topicReq]);
            }

            $pool = $topicPools[$topicReq];
            if (count($pool) < 1) quiz_abort('В темі немає питань', ['topic' => $topicReq]);

            // split to parts of 40; always return exactly 40 (last part padded by random from same pool)
            $baseParts = (int)ceil(count($pool) / 40);
            if ($partReq > $baseParts) $partReq = $baseParts;

            $chunks = array_chunk($pool, 40);
            $chunk = $chunks[$partReq - 1] ?? [];
            $chunk = array_values(array_unique(array_map('intval', $chunk)));

            if (count($chunk) < 40) {
                // pad with deterministic random from remaining ids
                $remSet = array_diff($pool, $chunk);
                $padSeed = $seed !== 0 ? $seed : (int)abs(crc32($topicReq . '|part|' . $partReq));
                $pad = sample_ids(array_values($remSet), 40 - count($chunk), $padSeed);
                $chunk = array_values(array_unique(array_merge($chunk, $pad)));

                // if still < 40 (rare) allow reuse
                if (count($chunk) < 40) {
                    $more = sample_ids($pool, 40 - count($chunk), $padSeed + 1);
                    $chunk = array_values(array_merge($chunk, $more));
                }
                $chunk = array_slice($chunk, 0, 40);
            }

            // ensure exists in qMap
            $final = [];
            foreach ($chunk as $qid) if (isset($qMap[$qid])) $final[] = $qid;
            if (count($final) < 1) quiz_abort('Не вдалось сформувати іспит по темі', ['topic'=>$topicReq,'part'=>$partReq]);

            $qIds = $final;
        }

        // ===== TRAINER =====
        if ($mode === 'trainer' || $mode === 'trainer_mix' || $mode === 'trainer_topic') {
            $title = 'Тренажер';
            $timeLimit = 0; // ✅ unlimited
            $maxMistakes = 999999; // ✅ unlimited mistakes

            if ($mode === 'trainer_topic') {
                if ($topicReq === '' || !isset($topicPools[$topicReq]) || !is_array($topicPools[$topicReq])) {
                    quiz_abort('Невірна тема для тренажера', ['topic' => $topicReq]);
                }
                $topic = $topicReq;
                $pool = $topicPools[$topicReq];
                $seed = $seed !== 0 ? $seed : (int)abs(crc32($topicReq . '|trainer'));
                $qIds = sample_ids($pool, 40, $seed);
            } else {
                $topic = ($mistakesOnly || $mode === 'trainer') ? 'Повтор помилок' : 'Мікс питань';
                $all = array_keys($qMap);

                if (count($all) < 1) quiz_abort('Немає питань для тренажера', []);

                if ($mistakesOnly && ($mode === 'trainer')) {
                    $mIds = progress_all_mistakes_ids((string)$uid);
                    $filtered = [];
                    foreach ($mIds as $qid) if (isset($qMap[$qid])) $filtered[] = $qid;

                    if (count($filtered) >= 1) {
                        $seed = $seed !== 0 ? $seed : (int)(time() % 1000000);
                        $qIds = sample_ids($filtered, 40, $seed);
                    } else {
                        $seed = $seed !== 0 ? $seed : 777;
                        $qIds = sample_ids($all, 40, $seed);
                    }
                } else {
                    $seed = $seed !== 0 ? $seed : 777;
                    $qIds = sample_ids($all, 40, $seed);
                }
            }
        }

        // Safety: filter to existing qMap only
        $qIdsFiltered = [];
        foreach ($qIds as $qid) {
            $qid = (int)$qid;
            if ($qid > 0 && isset($qMap[$qid])) $qIdsFiltered[] = $qid;
        }
        $qIds = array_values($qIdsFiltered);

        if (count($qIds) < 1) {
            quiz_abort('Немає питань для старту (після обрізки)', [
                'mode' => $mode,
                'topic' => $topicReq,
                'test_id' => $testId,
            ]);
        }

        $_SESSION['quiz'] = [
            'mode' => $mode,
            'test_id' => $testId,
            'title' => $title,
            'topic' => $topic,
            'topic_req' => $topicReq,
            'part' => $partReq,
            'q_ids' => $qIds,
            'idx' => 0,
            'total' => count($qIds),
            'answers' => [],
            'started_at' => time(),
            'max_mistakes' => $maxMistakes,
            'time_limit_sec' => $timeLimit,
            'seed' => $seed,
            'mistakes_only' => $mistakesOnly ? 1 : 0,
        ];

        quiz_redirect('/account/quiz.php');
    }

    if ($action === 'answer') {
        if (!isset($_SESSION['quiz']) || !is_array($_SESSION['quiz']) || !quiz_session_is_valid($_SESSION['quiz'])) {
            quiz_redirect('/account/tests.php');
        }

        $quiz = $_SESSION['quiz'];

        $choice = (int)($_POST['choice'] ?? 0);
        $idx = (int)($quiz['idx'] ?? 0);
        $qIds = $quiz['q_ids'] ?? [];
        $total = (int)($quiz['total'] ?? 0);

        if (!is_array($qIds) || $idx < 0 || $idx >= $total) {
            quiz_redirect('/account/quiz.php?action=finish');
        }

        $qid = (int)$qIds[$idx];
        $q = $qMap[$qid] ?? null;
        if (!is_array($q)) {
            quiz_abort('Питання не знайдено', ['qid' => $qid, 'idx' => $idx]);
        }

        $correct = (int)$q['correct'];
        $isCorrect = ($choice === $correct);

        if (!isset($quiz['answers']) || !is_array($quiz['answers'])) $quiz['answers'] = [];

        // Save answer once
        if (!array_key_exists((string)$idx, $quiz['answers'])) {
            $quiz['answers'][(string)$idx] = [
                'qid' => $qid,
                'choice' => $choice,
                'correct' => $correct,
                'is_correct' => $isCorrect,
                'at' => time(),
            ];
        }

        // ✅ DO NOT auto-next (stay on same idx to show explain)
        $_SESSION['quiz'] = $quiz;

        // ✅ auto finish on mistakes limit (exam/test)
        $mistakes = quiz_count_mistakes($quiz);
        $maxMistakes = (int)($quiz['max_mistakes'] ?? 3);
        if ($mistakes >= $maxMistakes) {
            quiz_redirect('/account/quiz.php?action=finish');
        }

        // ✅ if all answered -> finish
        $answered = is_array($quiz['answers']) ? count($quiz['answers']) : 0;
        if ($answered >= $total) {
            quiz_redirect('/account/quiz.php?action=finish');
        }

        quiz_redirect('/account/quiz.php');
    }

    if ($action === 'go') {
        if (!isset($_SESSION['quiz']) || !is_array($_SESSION['quiz']) || !quiz_session_is_valid($_SESSION['quiz'])) {
            quiz_redirect('/account/tests.php');
        }
        $quiz = $_SESSION['quiz'];
        $to = (int)($_POST['to'] ?? 0);
        $total = (int)($quiz['total'] ?? 0);
        if ($to >= 0 && $to < $total) {
            $quiz['idx'] = $to;
            $_SESSION['quiz'] = $quiz;
        }
        quiz_redirect('/account/quiz.php');
    }

    quiz_redirect('/account/quiz.php');
}

/** -------- GET: finish / show question -------- */
$action = (string)($_GET['action'] ?? '');

if ($action === 'finish') {
    if (!isset($_SESSION['quiz']) || !is_array($_SESSION['quiz']) || !quiz_session_is_valid($_SESSION['quiz'])) {
        quiz_redirect('/account/tests.php');
    }

    $quiz = $_SESSION['quiz'];
    $answers = $quiz['answers'] ?? [];
    if (!is_array($answers)) $answers = [];

    $mistakes = quiz_count_mistakes($quiz);
    $total = (int)($quiz['total'] ?? 0);
    $answered = count($answers);

    $timeLimit = (int)($quiz['time_limit_sec'] ?? 0);
    $started = (int)($quiz['started_at'] ?? time());
    $spent = time() - $started;

    $maxMistakes = (int)($quiz['max_mistakes'] ?? 3);

    $passed = ($mistakes < $maxMistakes) && ($answered >= $total) && ($total > 0);
    if ($timeLimit > 0 && $spent > $timeLimit) $passed = false;

    // ✅ Persist progress
    $mode = (string)($quiz['mode'] ?? 'test');
    $testId = (int)($quiz['test_id'] ?? 0);

    $wrongQids = [];
    foreach ($answers as $a) {
        if (!is_array($a)) continue;
        if (!empty($a['qid']) && isset($a['is_correct']) && $a['is_correct'] === false) {
            $wrongQids[] = (int)$a['qid'];
        }
    }
    $wrongQids = array_values(array_filter($wrongQids, fn($x)=> $x>0));

    $bucketTestId = ($mode === 'test' && $testId > 0) ? $testId : 0;
    if (count($wrongQids) > 0) {
        progress_add_mistakes((string)$uid, $bucketTestId, $wrongQids);
    }
    if ($passed) {
        if ($mode === 'test' && $testId > 0) {
            progress_mark_passed((string)$uid, $testId);
        } else {
            $key = passed_item_key($quiz);
            $pTitle = (string)($quiz['title'] ?? 'Режим');
            if (!empty($quiz['topic'])) $pTitle .= ' — ' . (string)$quiz['topic'];
            progress_mark_passed_item((string)$uid, $key, $pTitle);
        }
    }

    $csrf = csrf_token();
    ?>
    <!doctype html>
    <html lang="uk">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= h((string)($quiz['title'] ?? 'Результат')) ?> — ProstoPDR</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Unbounded:wght@500;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="/assets/css/style.css?v=4">
        <style>
            .pp-wrap{max-width:980px;margin:24px auto;padding:0 16px}
            .pp-card{background:#fff;border-radius:16px;padding:18px;box-shadow:0 8px 30px rgba(0,0,0,0.06)}
            .pp-title{font-size:22px;font-weight:900;margin:0 0 10px}
            .pp-row{display:flex;gap:14px;flex-wrap:wrap;margin-top:10px}
            .pp-pill{background:#f1f3f7;border-radius:999px;padding:8px 12px;font-weight:700}
            .pp-actions{margin-top:14px;display:flex;gap:10px;flex-wrap:wrap}
            .pp-btn{display:inline-block;padding:12px 14px;border-radius:14px;background:#0b1b14;color:#fff;text-decoration:none;border:none;font-weight:800}
            .pp-btn2{display:inline-block;padding:12px 14px;border-radius:14px;background:#f1f3f7;color:#111;text-decoration:none;font-weight:800}
            .pp-status-ok{color:#0a7a3d}
            .pp-status-bad{color:#b42318}
        </style>
    </head>
    <body>
    <div class="pp-wrap">
        <div class="pp-card">
            <h1 class="pp-title"><?= h((string)($quiz['title'] ?? 'Результат')) ?></h1>
            <?php if (!empty($quiz['topic'])): ?>
                <div style="opacity:.8;font-weight:700;margin-top:-4px;"><?= h((string)$quiz['topic']) ?></div>
            <?php endif; ?>

            <div class="pp-row">
                <div class="pp-pill">Відповіді: <?= (int)$answered ?> / <?= (int)$total ?></div>
                <div class="pp-pill">Помилки: <?= (int)$mistakes ?> / <?= (int)$maxMistakes ?></div>
                <?php if ($timeLimit > 0): ?>
                    <div class="pp-pill">Час: <?= h(format_mmss((int)$spent)) ?> (ліміт <?= h(format_mmss((int)$timeLimit)) ?>)</div>
                <?php else: ?>
                    <div class="pp-pill">Час: без ліміту</div>
                <?php endif; ?>
                <div class="pp-pill">Статус:
                    <b class="<?= $passed ? 'pp-status-ok' : 'pp-status-bad' ?>"><?= $passed ? 'Складено' : 'Не складено' ?></b>
                </div>
            </div>

            <div class="pp-actions">
                <form method="post" action="/account/quiz.php" style="margin:0">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="reset">
                    <button class="pp-btn" type="submit">Завершити та вийти</button>
                </form>
                <a class="pp-btn2" href="/account/tests.php">До списку тестів</a>
                <a class="pp-btn2" href="/account/index.php">В кабінет</a>
            </div>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

// Default: show current question
if (!isset($_SESSION['quiz']) || !is_array($_SESSION['quiz']) || !quiz_session_is_valid($_SESSION['quiz'])) {
    quiz_redirect('/account/tests.php');
}

$quiz = $_SESSION['quiz'];

$qIds = $quiz['q_ids'] ?? [];
$idx = (int)($quiz['idx'] ?? 0);
$total = (int)($quiz['total'] ?? 0);

if (!is_array($qIds) || $total < 1) {
    quiz_abort('Сесія тесту пошкоджена', ['quiz' => $quiz]);
}

if ($idx < 0) $idx = 0;
if ($idx >= $total) {
    quiz_redirect('/account/quiz.php?action=finish');
}

$qid = (int)$qIds[$idx];
$q = $qMap[$qid] ?? null;
if (!is_array($q)) {
    $quiz_abort = ['qid' => $qid, 'idx' => $idx];
    quiz_abort('Питання не знайдено (можливо обрізано)', $quiz_abort);
}

$answers = $quiz['answers'] ?? [];
if (!is_array($answers)) $answers = [];

$timeLimit = (int)($quiz['time_limit_sec'] ?? 0);
$started = (int)($quiz['started_at'] ?? time());
$spent = time() - $started;
$timeLeft = $timeLimit > 0 ? max(0, $timeLimit - $spent) : 0;

// time over -> finish (only if limited)
if ($timeLimit > 0 && $spent > $timeLimit) {
    quiz_redirect('/account/quiz.php?action=finish');
}

$mistakes = quiz_count_mistakes($quiz);
$maxMistakes = (int)($quiz['max_mistakes'] ?? 3);
if ($mistakes >= $maxMistakes) {
    quiz_redirect('/account/quiz.php?action=finish');
}

$csrf = csrf_token();

$currentAnswer = $answers[(string)$idx] ?? null;
$alreadyAnswered = is_array($currentAnswer) && isset($currentAnswer['choice']);
$chosen = $alreadyAnswered ? (int)$currentAnswer['choice'] : 0;
$correct = (int)$q['correct'];

$title = (string)($quiz['title'] ?? 'Тест');
$topic = (string)($quiz['topic'] ?? '');

?>
<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?> — ProstoPDR</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Unbounded:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/style.css?v=4">
    <style>
        .pp-wrap{max-width:980px;margin:24px auto;padding:0 16px}
        .pp-card{background:#fff;border-radius:18px;padding:18px;box-shadow:0 8px 30px rgba(0,0,0,0.06);border:1px solid rgba(12,32,22,.06)}
        .pp-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:10px}
        .pp-hgroup{display:flex;flex-direction:column;gap:4px}
        .pp-title{font-size:18px;font-weight:900;margin:0}
        .pp-topic{opacity:.7;font-weight:800}
        .pp-q{font-size:18px;font-weight:800;margin:12px 0}
        .pp-img{margin:10px 0}
        .pp-img img{max-width:100%;height:auto;border-radius:14px;display:block;border:1px solid rgba(12,32,22,.08)}
        .pp-opt{display:block;width:100%;text-align:left;border:1px solid rgba(12,32,22,.10);background:#fff;border-radius:14px;padding:12px 12px;margin:10px 0;cursor:pointer;font-weight:700}
        .pp-opt:hover{background:rgba(11,27,20,.03)}
        .pp-opt[disabled]{cursor:default;opacity:1}
        .pp-opt.is-correct{border-color:rgba(10,122,61,.35);background:rgba(10,122,61,.08)}
        .pp-opt.is-wrong{border-color:rgba(180,35,24,.35);background:rgba(180,35,24,.08)}
        .pp-explain{margin-top:12px;border-radius:14px;border:1px solid rgba(12,32,22,.10);background:rgba(11,27,20,.02);padding:12px}
        .pp-explain__t{font-weight:900;margin-bottom:6px}
        .pp-explain__b{opacity:.9;font-weight:650;line-height:1.45}

        .pp-progress{display:flex;align-items:center;gap:10px;margin-bottom:12px}
        .pp-navbtn{width:44px;height:44px;border-radius:999px;border:1px solid rgba(12,32,22,.10);background:rgba(11,27,20,.03);display:flex;align-items:center;justify-content:center;font-weight:900}
        .pp-navbtn:hover{background:rgba(11,27,20,.06)}
        .pp-strip{flex:1;overflow:hidden}
        .pp-strip__inner{display:flex;gap:10px;overflow-x:auto;scroll-behavior:smooth;padding:6px 2px}
        .pp-strip__inner::-webkit-scrollbar{height:8px}
        .pp-strip__inner::-webkit-scrollbar-thumb{background:rgba(11,27,20,.12);border-radius:99px}

        .pp-dot{
            width:34px;height:34px;border-radius:999px;
            border:1px solid rgba(12,32,22,.16);
            background:#fff;
            display:flex;align-items:center;justify-content:center;
            font-weight:900;
            user-select:none;
        }
        .pp-dot.is-current{outline:2px solid rgba(10,122,61,.25);outline-offset:2px}
        .pp-dot.is-ok{border-color:rgba(10,122,61,.35);background:rgba(10,122,61,.10)}
        .pp-dot.is-bad{border-color:rgba(180,35,24,.35);background:rgba(180,35,24,.10)}

        .pp-goform{margin:0}
        .pp-gobtn{
            border:none;background:transparent;padding:0;margin:0;
            cursor:pointer;
        }

        .pp-bottom{
            position:sticky;
            bottom:0;
            margin-top:14px;
            padding-top:10px;
            background:linear-gradient(to top, #fff 70%, rgba(255,255,255,0));
        }
        .pp-bar{
            display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;
            border:1px solid rgba(12,32,22,.10);
            background:rgba(11,27,20,.02);
            border-radius:16px;
            padding:12px;
        }
        .pp-bar__left{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
        .pp-pill{
            background:#fff;border:1px solid rgba(12,32,22,.10);
            border-radius:999px;padding:8px 12px;font-weight:900;
        }
        .pp-pill small{opacity:.7;font-weight:800}
        .pp-btn{display:inline-flex;align-items:center;justify-content:center;padding:12px 14px;border-radius:14px;background:#0b1b14;color:#fff;text-decoration:none;border:none;font-weight:900}
        .pp-btn2{display:inline-flex;align-items:center;justify-content:center;padding:12px 14px;border-radius:14px;background:#f1f3f7;color:#111;text-decoration:none;border:none;font-weight:900}
        .pp-btn:disabled{opacity:.6}

        @media (max-width: 560px){
            .pp-wrap{margin:14px auto}
            .pp-card{padding:14px;border-radius:16px}
            .pp-navbtn{width:42px;height:42px}
            .pp-dot{width:32px;height:32px}
        }
    </style>
</head>
<body>

<div class="pp-wrap">
    <div class="pp-card" id="quizRoot"
         data-timeleft="<?= (int)$timeLeft ?>"
         data-timelimit="<?= (int)$timeLimit ?>">

        <div class="pp-progress">
            <button class="pp-navbtn" type="button" id="ppPrev" aria-label="Ліворуч">‹</button>
            <div class="pp-strip">
                <div class="pp-strip__inner" id="ppStrip">
                    <?php for ($i=0; $i<$total; $i++):
                        $a = $answers[(string)$i] ?? null;
                        $cls = [];
                        if ($i === $idx) $cls[] = 'is-current';
                        if (is_array($a) && isset($a['is_correct'])) {
                            $cls[] = $a['is_correct'] ? 'is-ok' : 'is-bad';
                        }
                        $clsStr = implode(' ', $cls);
                        ?>
                        <form class="pp-goform" method="post" action="/account/quiz.php">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="action" value="go">
                            <input type="hidden" name="to" value="<?= (int)$i ?>">
                            <button class="pp-gobtn" type="submit" aria-label="Питання <?= (int)($i+1) ?>">
                                <div class="pp-dot <?= h($clsStr) ?>"><?= (int)($i+1) ?></div>
                            </button>
                        </form>
                    <?php endfor; ?>
                </div>
            </div>
            <button class="pp-navbtn" type="button" id="ppNext" aria-label="Праворуч">›</button>
        </div>

        <div class="pp-head">
            <div class="pp-hgroup">
                <h1 class="pp-title"><?= h($title) ?></h1>
                <?php if ($topic !== ''): ?>
                    <div class="pp-topic"><?= h($topic) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="pp-q"><?= h((string)$q['question']) ?></div>

        <?php if (!empty($q['image']) && is_string($q['image'])): ?>
            <div class="pp-img">
                <img src="<?= h($q['image']) ?>" alt="Question image">
            </div>
        <?php endif; ?>

        <form method="post" action="/account/quiz.php" id="answerForm">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="answer">

            <?php foreach ($q['options'] as $i => $opt): ?>
                <?php
                $n = $i + 1;

                $btnCls = '';
                $disabled = false;

                if ($alreadyAnswered) {
                    $disabled = true;
                    if ($n === $correct) $btnCls = 'is-correct';
                    if ($n === $chosen && $chosen !== $correct) $btnCls = 'is-wrong';
                }
                ?>
                <button
                    class="pp-opt <?= h($btnCls) ?>"
                    type="submit"
                    name="choice"
                    value="<?= (int)$n ?>"
                    <?= $disabled ? 'disabled' : '' ?>
                >
                    <?= h((string)$n) ?>) <?= h((string)$opt) ?>
                </button>
            <?php endforeach; ?>
        </form>

        <?php if ($alreadyAnswered): ?>
            <div class="pp-explain">
                <div class="pp-explain__t">Пояснення</div>
                <div class="pp-explain__b">
                    <?= $q['explain'] !== '' ? nl2br(h((string)$q['explain'])) : '<span style="opacity:.7">Пояснення поки відсутнє.</span>' ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="pp-bottom">
            <div class="pp-bar">
                <div class="pp-bar__left">
                    <div class="pp-pill"><small>Питання</small> <span><?= (int)($idx+1) ?></span>/<span><?= (int)$total ?></span></div>
                    <div class="pp-pill"><small>Помилки</small> <span id="mistakesNow"><?= (int)$mistakes ?></span>/<span><?= (int)$maxMistakes ?></span></div>

                    <?php if ($timeLimit > 0): ?>
                        <div class="pp-pill"><small>Час</small> <span id="timerText"><?= h(format_mmss((int)$timeLeft)) ?></span> / <span><?= h(format_mmss((int)$timeLimit)) ?></span></div>
                    <?php else: ?>
                        <div class="pp-pill"><small>Час</small> <span id="timerText">без ліміту</span></div>
                    <?php endif; ?>
                </div>

                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
                    <a class="pp-btn2" href="/account/quiz.php?action=finish">Завершити</a>

                    <?php $isLast = ($idx >= $total - 1); ?>

                    <?php if ($alreadyAnswered && !$isLast): ?>
                        <form method="post" action="/account/quiz.php" style="margin:0">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="action" value="go">
                            <input type="hidden" name="to" value="<?= (int)($idx+1) ?>">
                            <button class="pp-btn" type="submit">Далі →</button>
                        </form>
                    <?php elseif ($alreadyAnswered && $isLast): ?>
                        <a class="pp-btn" href="/account/quiz.php?action=finish">Завершити</a>
                    <?php else: ?>
                        <button class="pp-btn" type="button" disabled>Обери відповідь</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
(function(){
    const root = document.getElementById('quizRoot');
    const timerEl = document.getElementById('timerText');
    if (!root || !timerEl) return;

    let left = parseInt(root.getAttribute('data-timeleft') || '0', 10);
    const limit = parseInt(root.getAttribute('data-timelimit') || '0', 10);

    function mmss(sec){
        sec = Math.max(0, sec|0);
        const m = Math.floor(sec/60);
        const s = sec%60;
        return m + ':' + String(s).padStart(2,'0');
    }

    if (limit > 0) {
        timerEl.textContent = mmss(left);
        setInterval(function(){
            left -= 1;
            if (left <= 0) {
                timerEl.textContent = '0:00';
                window.location.href = '/account/quiz.php?action=finish';
                return;
            }
            timerEl.textContent = mmss(left);
        }, 1000);
    }

    const strip = document.getElementById('ppStrip');
    const prev = document.getElementById('ppPrev');
    const next = document.getElementById('ppNext');

    function scrollByAmount(dir){
        if (!strip) return;
        const w = strip.clientWidth || 280;
        strip.scrollBy({ left: dir * Math.max(160, Math.floor(w*0.75)), behavior: 'smooth' });
    }

    if (prev) prev.addEventListener('click', () => scrollByAmount(-1));
    if (next) next.addEventListener('click', () => scrollByAmount(+1));

    const currentDot = strip ? strip.querySelector('.pp-dot.is-current') : null;
    if (strip && currentDot) {
        const r = currentDot.getBoundingClientRect();
        const sr = strip.getBoundingClientRect();
        if (r.left < sr.left || r.right > sr.right) {
            strip.scrollBy({ left: (r.left - sr.left) - 60, behavior: 'smooth' });
        }
    }
})();
</script>

</body>
</html>