<?php
// src/progress_store.php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Progress store (Postgres, Railway)
 *
 * Drop-in API (під твої старі виклики):
 * - progress_add_mistakes(string $uid, int $testId, array $qids): void
 * - progress_mark_passed(string $uid, int $testId): void
 * - progress_all_mistakes_ids(string $uid): array
 *
 * + helpers:
 * - progress_user_mistakes_by_test(string $uid): array<int, array<int>>
 */

function pdoi(): PDO {
  $pdo = db();
  progress_ensure_schema($pdo);
  return $pdo;
}

function progress_ensure_schema(PDO $pdo): void {
  // складені тести
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS user_passed_tests (
      user_id   TEXT NOT NULL,
      test_id   INT  NOT NULL,
      passed_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
      PRIMARY KEY (user_id, test_id)
    );
  ");

  // помилки по питаннях
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS user_test_mistakes (
      user_id    TEXT NOT NULL,
      test_id    INT  NOT NULL,
      question_id INT NOT NULL,
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
      PRIMARY KEY (user_id, test_id, question_id)
    );
  ");

  // індекси (опційно, але корисно)
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_mistakes_user ON user_test_mistakes(user_id);");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_mistakes_test ON user_test_mistakes(test_id);");
}

/**
 * Додає помилки (question IDs) до тесту.
 * Дублікати ігноруються завдяки PRIMARY KEY.
 */
function progress_add_mistakes(string $uid, int $testId, array $qids): void {
  $uid = trim($uid);
  if ($uid === '' || $testId <= 0) return;

  // чистимо список
  $clean = [];
  foreach ($qids as $qid) {
    $qid = (int)$qid;
    if ($qid > 0) $clean[$qid] = true;
  }
  if (!$clean) return;

  $pdo = pdoi();

  // Вставка пачкою. ON CONFLICT -> ignore
  $values = [];
  $params = [];
  $i = 0;
  foreach (array_keys($clean) as $qid) {
    $values[] = "(:u{$i}, :t{$i}, :q{$i})";
    $params["u{$i}"] = $uid;
    $params["t{$i}"] = $testId;
    $params["q{$i}"] = $qid;
    $i++;
  }

  $sql = "
    INSERT INTO user_test_mistakes (user_id, test_id, question_id)
    VALUES " . implode(',', $values) . "
    ON CONFLICT (user_id, test_id, question_id) DO NOTHING
  ";

  $st = $pdo->prepare($sql);
  foreach ($params as $k => $v) $st->bindValue(':' . $k, $v);
  $st->execute();
}

/**
 * Позначає тест як “складений”.
 */
function progress_mark_passed(string $uid, int $testId): void {
  $uid = trim($uid);
  if ($uid === '' || $testId <= 0) return;

  $pdo = pdoi();
  $st = $pdo->prepare("
    INSERT INTO user_passed_tests (user_id, test_id)
    VALUES (:u, :t)
    ON CONFLICT (user_id, test_id) DO UPDATE SET passed_at = NOW()
  ");
  $st->execute([':u' => $uid, ':t' => $testId]);
}

/**
 * Повертає ВСІ question_id, де користувач колись помилився (без дублікатів).
 */
function progress_all_mistakes_ids(string $uid): array {
  $uid = trim($uid);
  if ($uid === '') return [];

  $pdo = pdoi();
  $st = $pdo->prepare("
    SELECT DISTINCT question_id
    FROM user_test_mistakes
    WHERE user_id = :u
    ORDER BY question_id
  ");
  $st->execute([':u' => $uid]);
  $rows = $st->fetchAll(PDO::FETCH_COLUMN);
  return array_map('intval', $rows ?: []);
}

/**
 * Повертає помилки по тестах:
 * [ test_id => [question_id, question_id...] ]
 */
function progress_user_mistakes_by_test(string $uid): array {
  $uid = trim($uid);
  if ($uid === '') return [];

  $pdo = pdoi();
  $st = $pdo->prepare("
    SELECT test_id, question_id
    FROM user_test_mistakes
    WHERE user_id = :u
    ORDER BY test_id, question_id
  ");
  $st->execute([':u' => $uid]);
  $out = [];
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $tid = (int)$r['test_id'];
    $qid = (int)$r['question_id'];
    $out[$tid] ??= [];
    $out[$tid][] = $qid;
  }
  return $out;
}