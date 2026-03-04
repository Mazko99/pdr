<?php
// src/progress_store.php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Drop-in replacement for old JSON progress_* API.
 * Keeps same function names so you almost don't touch quiz.php/tests.php.
 *
 * Tables:
 * - user_passed_tests   : складені тести
 * - user_theory_done    : пройдена теорія
 * - user_mistakes       : питання з помилками (для "помилки" режимів)
 */

function progress_ensure_schema(PDO $pdo): void {
  // PASSED TESTS
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS user_passed_tests (
      user_id   TEXT NOT NULL,
      test_id   INT  NOT NULL,
      passed_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
      PRIMARY KEY (user_id, test_id)
    );
  ");

  // THEORY DONE
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS user_theory_done (
      user_id   TEXT NOT NULL,
      topic_key TEXT NOT NULL,
      done_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
      PRIMARY KEY (user_id, topic_key)
    );
  ");

  // MISTAKES (per "bucket"/test)
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS user_mistakes (
      user_id     TEXT NOT NULL,
      test_bucket INT  NOT NULL,
      question_id INT  NOT NULL,
      created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
      PRIMARY KEY (user_id, test_bucket, question_id)
    );
  ");
}

/** (compat) no longer used, but kept so old code won't crash if called */
function progress_path(): string { return ''; }
function progress_load(): array { return ['users' => []]; }
function progress_save(array $data): void {}

/**
 * ✅ MAIN: return user progress in the SAME SHAPE as old JSON progress_user_get()
 * [
 *   'passed_tests' => ['12'=>true, ...],
 *   'mistakes'     => ['0'=>[1,2], '15'=>[10,11], ...],
 *   'theory_done'  => ['Topic name'=>['done'=>true], ...],
 *   'updated_at'   => '...'
 * ]
 */
function progress_user_get(string $uid): array {
  $pdo = db();
  progress_ensure_schema($pdo);

  // passed tests -> map testIdStr => true
  $stmt = $pdo->prepare("SELECT test_id FROM user_passed_tests WHERE user_id = ? ORDER BY test_id ASC");
  $stmt->execute([$uid]);
  $rows = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

  $passed = [];
  if (is_array($rows)) {
    foreach ($rows as $tid) {
      $tid = (int)$tid;
      if ($tid > 0) $passed[(string)$tid] = true;
    }
  }

  // theory -> map topic => ['done'=>true]
  $stmt = $pdo->prepare("SELECT topic_key FROM user_theory_done WHERE user_id = ?");
  $stmt->execute([$uid]);
  $trows = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

  $theory = [];
  if (is_array($trows)) {
    foreach ($trows as $k) {
      $k = trim((string)$k);
      if ($k !== '') $theory[$k] = ['done' => true];
    }
  }

  // mistakes grouped by bucket/test
  $stmt = $pdo->prepare("
    SELECT test_bucket, question_id
    FROM user_mistakes
    WHERE user_id = ?
    ORDER BY test_bucket ASC, question_id ASC
  ");
  $stmt->execute([$uid]);
  $mrows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $mistakes = [];
  if (is_array($mrows)) {
    foreach ($mrows as $r) {
      $bucket = (int)($r['test_bucket'] ?? 0);
      $qid    = (int)($r['question_id'] ?? 0);
      if ($qid <= 0) continue;
      $k = (string)$bucket;
      if (!isset($mistakes[$k]) || !is_array($mistakes[$k])) $mistakes[$k] = [];
      $mistakes[$k][] = $qid;
    }
  }

  // make unique + sorted each bucket (like JSON version)
  foreach ($mistakes as $k => $arr) {
    $arr = array_values(array_unique(array_map('intval', $arr)));
    sort($arr);
    $mistakes[$k] = $arr;
  }

  return [
    'passed_tests' => $passed,
    'mistakes' => $mistakes,
    'theory_done' => $theory,
    'updated_at' => date('c'),
  ];
}

/** (compat) previously wrote whole blob; now DB-based so no-op */
function progress_user_set(string $uid, array $u): void {}

/** Add mistakes qids into bucket(testId or 0) */
function progress_add_mistakes(string $uid, int $testId, array $qids): void {
  $pdo = db();
  progress_ensure_schema($pdo);

  $bucket = (int)$testId;
  $qids = array_values(array_unique(array_map('intval', $qids)));

  // Postgres upsert/do nothing
  foreach ($qids as $qid) {
    if ($qid <= 0) continue;
    try {
      $stmt = $pdo->prepare("
        INSERT INTO user_mistakes (user_id, test_bucket, question_id)
        VALUES (?, ?, ?)
        ON CONFLICT (user_id, test_bucket, question_id) DO NOTHING
      ");
      $stmt->execute([$uid, $bucket, $qid]);
    } catch (Throwable $e) {
      // fallback if DB is MySQL-like
      $stmt = $pdo->prepare("INSERT IGNORE INTO user_mistakes (user_id, test_bucket, question_id) VALUES (?,?,?)");
      $stmt->execute([$uid, $bucket, $qid]);
    }
  }
}

/** Mark test as passed */
function progress_mark_passed(string $uid, int $testId): void {
  if ($testId <= 0) return;
  $pdo = db();
  progress_ensure_schema($pdo);

  try {
    $stmt = $pdo->prepare("
      INSERT INTO user_passed_tests (user_id, test_id)
      VALUES (?, ?)
      ON CONFLICT (user_id, test_id) DO UPDATE SET passed_at = NOW()
    ");
    $stmt->execute([$uid, $testId]);
  } catch (Throwable $e) {
    $stmt = $pdo->prepare("INSERT IGNORE INTO user_passed_tests (user_id, test_id) VALUES (?,?)");
    $stmt->execute([$uid, $testId]);
  }
}

/** Return all unique mistake question ids for user (across buckets) */
function progress_all_mistakes_ids(string $uid): array {
  $pdo = db();
  progress_ensure_schema($pdo);

  $stmt = $pdo->prepare("SELECT DISTINCT question_id FROM user_mistakes WHERE user_id = ? ORDER BY question_id ASC");
  $stmt->execute([$uid]);
  $rows = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

  $ids = [];
  if (is_array($rows)) {
    foreach ($rows as $qid) {
      $qid = (int)$qid;
      if ($qid > 0) $ids[] = $qid;
    }
  }
  return $ids;
}

/** Optional helper: mark theory done */
function progress_mark_theory_done(string $uid, string $topicKey): void {
  $topicKey = trim($topicKey);
  if ($topicKey === '') return;

  $pdo = db();
  progress_ensure_schema($pdo);

  try {
    $stmt = $pdo->prepare("
      INSERT INTO user_theory_done (user_id, topic_key)
      VALUES (?, ?)
      ON CONFLICT (user_id, topic_key) DO UPDATE SET done_at = NOW()
    ");
    $stmt->execute([$uid, $topicKey]);
  } catch (Throwable $e) {
    $stmt = $pdo->prepare("INSERT IGNORE INTO user_theory_done (user_id, topic_key) VALUES (?,?)");
    $stmt->execute([$uid, $topicKey]);
  }
}