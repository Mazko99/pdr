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
  static $done = false;
  if ($done) return;

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

  $done = true;
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

/** ✅ ALIAS for older code that calls progress_get_user() */
function progress_get_user(string $uid): array {
  return progress_user_get($uid);
}

/** (compat) previously wrote whole blob; now DB-based so no-op */
function progress_user_set(string $uid, array $u): void {}

/** ✅ ALIAS for older code that calls progress_user_update/set etc (safe no-op) */
function progress_set_user(string $uid, array $u): void {
  progress_user_set($uid, $u);
}

/**
 * Add mistakes qids into bucket(testId or 0).
 *
 * ✅ IMPORTANT FIX:
 * - If $testId > 0, we ALSO write the same mistakes into bucket 0 (global),
 *   so any statistics page that reads only bucket "0" will still show mistakes.
 */
function progress_add_mistakes(string $uid, int $testId, array $qids): void {
  $pdo = db();
  progress_ensure_schema($pdo);

  $testId = (int)$testId;

  // нормалізація qids
  $qids = array_values(array_unique(array_map('intval', $qids)));
  $qids = array_values(array_filter($qids, fn($x) => (int)$x > 0));
  if (empty($qids)) return;

  // ✅ buckets: always write to requested bucket; additionally to bucket 0 if testId>0
  $buckets = [$testId];
  if ($testId > 0) $buckets[] = 0;
  $buckets = array_values(array_unique($buckets));

  // 1 prepared stmt for speed
  $stmt = $pdo->prepare("
    INSERT INTO user_mistakes (user_id, test_bucket, question_id)
    VALUES (?, ?, ?)
    ON CONFLICT (user_id, test_bucket, question_id) DO NOTHING
  ");

  foreach ($buckets as $bucket) {
    $bucket = (int)$bucket;
    foreach ($qids as $qid) {
      $stmt->execute([$uid, $bucket, (int)$qid]);
    }
  }
}

/** ✅ optional alias if somewhere used different name */
function progress_mistakes_add(string $uid, int $testId, array $qids): void {
  progress_add_mistakes($uid, $testId, $qids);
}

/** Mark test as passed */
function progress_mark_passed(string $uid, int $testId): void {
  if ($testId <= 0) return;
  $pdo = db();
  progress_ensure_schema($pdo);

  $stmt = $pdo->prepare("
    INSERT INTO user_passed_tests (user_id, test_id)
    VALUES (?, ?)
    ON CONFLICT (user_id, test_id) DO UPDATE SET passed_at = NOW()
  ");
  $stmt->execute([$uid, (int)$testId]);
}

/** ✅ alias just in case old code calls progress_test_passed_add */
function progress_test_passed_add(string $uid, int $testId): void {
  progress_mark_passed($uid, $testId);
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

  $stmt = $pdo->prepare("
    INSERT INTO user_theory_done (user_id, topic_key)
    VALUES (?, ?)
    ON CONFLICT (user_id, topic_key) DO UPDATE SET done_at = NOW()
  ");
  $stmt->execute([$uid, $topicKey]);
}

/** ✅ alias for older code */
function progress_theory_done_mark(string $uid, string $topicKey): void {
  progress_mark_theory_done($uid, $topicKey);
}