<?php
// public/admin/import_questions.php
// Запуск: php public/admin/import_questions.php /absolute/path/questions_export.json /absolute/path/tests_export.json
// Або з браузера (не рекомендовано на проді) - тоді вкажи шлях у $QUESTIONS_JSON / $TESTS_JSON.

declare(strict_types=1);

require __DIR__ . '/../../src/db.php';

$QUESTIONS_JSON = $argv[1] ?? (__DIR__ . '/../../data/questions_export.json');
$TESTS_JSON     = $argv[2] ?? (__DIR__ . '/../../data/tests_export.json');

if (!file_exists($QUESTIONS_JSON)) { die("No questions json: $QUESTIONS_JSON\n"); }
if (!file_exists($TESTS_JSON)) { die("No tests json: $TESTS_JSON\n"); }

$questions = json_decode(file_get_contents($QUESTIONS_JSON), true);
$tests     = json_decode(file_get_contents($TESTS_JSON), true);

if (!is_array($questions) || !is_array($tests)) { die("Bad JSON\n"); }

$pdo = db();

$pdo->exec("SET NAMES utf8mb4");

$pdo->beginTransaction();

try {
  // кеш тем
  $topicIdByTitle = [];
  $stmtTopicSel = $pdo->prepare("SELECT id FROM topics WHERE title = ?");
  $stmtTopicIns = $pdo->prepare("INSERT INTO topics (slug, title, question_count) VALUES (?, ?, 0)");

  $stmtQIns = $pdo->prepare("INSERT INTO questions (topic_id, qnum, text, image_json, correct_key) VALUES (?, ?, ?, ?, NULL)");
  $stmtOIns = $pdo->prepare("INSERT INTO options (question_id, opt_key, text) VALUES (?, ?, ?)");

  // ВАЖЛИВО: ми не використовуємо поле id з JSON, бо в БД буде свій AUTO_INCREMENT.
  $dbQuestionIdByJsonId = [];

  foreach ($questions as $q) {
    $title = trim((string)$q['topic']);

    if (!isset($topicIdByTitle[$title])) {
      $stmtTopicSel->execute([$title]);
      $tid = $stmtTopicSel->fetchColumn();

      if (!$tid) {
        $slug = slugify($title);
        $stmtTopicIns->execute([$slug, $title]);
        $tid = (int)$pdo->lastInsertId();
      } else {
        $tid = (int)$tid;
      }
      $topicIdByTitle[$title] = $tid;
    }

    $topicId = $topicIdByTitle[$title];
    $qnum = (int)$q['qnum'];
    $text = trim((string)$q['text']);
    $images = json_encode($q['images'] ?? [], JSON_UNESCAPED_UNICODE);

    $stmtQIns->execute([$topicId, $qnum, $text, $images]);
    $qid = (int)$pdo->lastInsertId();

    $dbQuestionIdByJsonId[(int)$q['id']] = $qid;

    foreach (($q['options'] ?? []) as $opt) {
      $k = (int)$opt['key'];
      $ot = trim((string)$opt['text']);
      $stmtOIns->execute([$qid, $k, $ot]);
    }
  }

  // update topic question_count
  $pdo->exec("UPDATE topics t SET question_count = (SELECT COUNT(*) FROM questions q WHERE q.topic_id = t.id)");

  // tests
  $stmtTestIns = $pdo->prepare("INSERT INTO tests (topic_id, type, title, time_limit_sec, max_mistakes, question_count) VALUES (?,?,?,?,?,?)");
  $stmtTQIns   = $pdo->prepare("INSERT INTO test_questions (test_id, question_id, ord) VALUES (?,?,?)");

  foreach ($tests as $t) {
    $title = trim((string)$t['topic']);
    if (!isset($topicIdByTitle[$title])) {
      // якщо тема була тільки в tests.json (малоймовірно)
      $stmtTopicSel->execute([$title]);
      $tid = $stmtTopicSel->fetchColumn();
      if (!$tid) {
        $slug = slugify($title);
        $stmtTopicIns->execute([$slug, $title]);
        $tid = (int)$pdo->lastInsertId();
      } else $tid = (int)$tid;
      $topicIdByTitle[$title] = $tid;
    }
    $topicId = $topicIdByTitle[$title];

    $type = ($t['type'] === 'exam') ? 'exam' : 'test';
    $time = (int)$t['time_limit_sec'];
    $maxMistakes = (int)$t['max_mistakes'];
    $qCount = ($type === 'exam') ? 40 : 20;

    $stmtTestIns->execute([$topicId, $type, (string)$t['title'], $time, $maxMistakes, $qCount]);
    $testId = (int)$pdo->lastInsertId();

    // Для "test" — фіксований набір питань (20). Для "exam" — НЕ зберігаємо питання, бо вони випадкові (40 з теми).
    if ($type === 'test') {
      $ord = 1;
      foreach ($t['question_ids'] as $jsonQid) {
        $dbQid = $dbQuestionIdByJsonId[(int)$jsonQid] ?? null;
        if (!$dbQid) continue;
        $stmtTQIns->execute([$testId, (int)$dbQid, $ord++]);
      }
    }
  }

  $pdo->commit();
  echo "OK: imported " . count($questions) . " questions, " . count($tests) . " tests\n";

} catch (Throwable $e) {
  $pdo->rollBack();
  die("ERROR: " . $e->getMessage() . "\n");
}

function slugify(string $s): string {
  $s = mb_strtolower($s, 'UTF-8');
  $s = preg_replace('~[^\pL\pN]+~u', '-', $s);
  $s = trim($s, '-');
  if ($s === '') $s = 'topic';
  return $s;
}
