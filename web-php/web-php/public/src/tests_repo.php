<?php
// src/tests_repo.php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function get_topics_with_tests(): array {
  $pdo = db();
  $topics = $pdo->query("SELECT id, slug, title, question_count FROM topics ORDER BY id ASC")->fetchAll();

  $stmt = $pdo->prepare("SELECT id, topic_id, type, title, time_limit_sec, max_mistakes, question_count
                         FROM tests WHERE topic_id = ? ORDER BY type ASC, id ASC");

  foreach ($topics as &$t) {
    $stmt->execute([(int)$t['id']]);
    $t['tests'] = $stmt->fetchAll();
  }
  return $topics;
}

function get_test(int $testId): ?array {
  $pdo = db();
  $stmt = $pdo->prepare("SELECT ts.*, tp.title AS topic_title, tp.slug AS topic_slug
                         FROM tests ts
                         JOIN topics tp ON tp.id = ts.topic_id
                         WHERE ts.id = ?");
  $stmt->execute([$testId]);
  $t = $stmt->fetch();
  return $t ?: null;
}

function get_questions_for_test(array $test): array {
  $pdo = db();
  $testId = (int)$test['id'];
  $type = $test['type'];

  if ($type === 'test') {
    $stmt = $pdo->prepare("
      SELECT q.id, q.text, q.image_json
      FROM test_questions tq
      JOIN questions q ON q.id = tq.question_id
      WHERE tq.test_id = ?
      ORDER BY tq.ord ASC
    ");
    $stmt->execute([$testId]);
    $qs = $stmt->fetchAll();
  } else {
    // exam: випадкові 40 з теми
    $stmt = $pdo->prepare("
      SELECT q.id, q.text, q.image_json
      FROM questions q
      WHERE q.topic_id = ?
      ORDER BY RAND()
      LIMIT ?
    ");
    $stmt->execute([(int)$test['topic_id'], (int)$test['question_count']]);
    $qs = $stmt->fetchAll();
  }

  // options
  $stmtOpt = $pdo->prepare("SELECT opt_key, text FROM options WHERE question_id = ? ORDER BY opt_key ASC");
  foreach ($qs as &$q) {
    $stmtOpt->execute([(int)$q['id']]);
    $q['options'] = $stmtOpt->fetchAll();
    $q['images'] = json_decode($q['image_json'] ?? '[]', true) ?: [];
    unset($q['image_json']);
  }
  return $qs;
}

function create_attempt(int $userId, int $testId): int {
  $pdo = db();
  $stmt = $pdo->prepare("INSERT INTO attempts (user_id, test_id) VALUES (?,?)");
  $stmt->execute([$userId, $testId]);
  return (int)$pdo->lastInsertId();
}

function finish_attempt(int $attemptId, int $timeSpentSec, int $correct, int $wrong, bool $passed): void {
  $pdo = db();
  $stmt = $pdo->prepare("UPDATE attempts
                         SET finished_at = NOW(),
                             time_spent_sec = ?,
                             score_correct = ?,
                             score_wrong = ?,
                             is_passed = ?
                         WHERE id = ?");
  $stmt->execute([$timeSpentSec, $correct, $wrong, $passed ? 1 : 0, $attemptId]);
}

function save_attempt_answer(int $attemptId, int $questionId, ?int $chosenKey, ?bool $isCorrect): void {
  $pdo = db();
  $stmt = $pdo->prepare("REPLACE INTO attempt_answers (attempt_id, question_id, chosen_key, is_correct)
                         VALUES (?,?,?,?)");
  $stmt->execute([$attemptId, $questionId, $chosenKey, is_null($isCorrect) ? null : ($isCorrect ? 1 : 0)]);
}

function get_correct_key(int $questionId): ?int {
  $pdo = db();
  $stmt = $pdo->prepare("SELECT correct_key FROM questions WHERE id = ?");
  $stmt->execute([$questionId]);
  $v = $stmt->fetchColumn();
  if ($v === false || $v === null) return null;
  return (int)$v;
}
