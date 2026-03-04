<?php
// src/progress_store.php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Progress store (Postgres, Railway)
 *
 * Основний API:
 * - progress_add_mistakes(string $uid, int $testId, array $qids): void
 * - progress_mark_passed(string $uid, int $testId): void
 * - progress_all_mistakes_ids(string $uid): array
 * - progress_user_mistakes_by_test(string $uid): array<int, array<int>>
 *
 * Compatibility API (щоб не ламати старий код quiz.php/tests.php/theory.php):
 * - progress_user_get(string $uid): array  -> ['passed_tests'=>map, 'theory_done'=>map]
 * - progress_user_set(string $uid, array $u): void
 */

function pdoi(): PDO {
  $pdo = db();
  progress_ensure_schema($pdo);
  return $pdo;
}

function progress_table_has_column(PDO $pdo, string $table, string $column): bool {
  $st = $pdo->prepare("
    SELECT 1
    FROM information_schema.columns
    WHERE table_schema = 'public'
      AND table_name = :t
      AND column_name = :c
    LIMIT 1
  ");
  $st->execute([':t' => $table, ':c' => $column]);
  return (bool)$st->fetchColumn();
}

function progress_pick_theory_key_column(PDO $pdo): string {
  // Найчастіші назви “ключа теми”
  $candidates = [
    'item',
    'topic_key',
    'theory_key',
    'theory_id',
    'topic',
    'slug',
    'key',
    'code',
  ];
  foreach ($candidates as $c) {
    if (progress_table_has_column($pdo, 'user_theory_done', $c)) return $c;
  }
  // якщо взагалі нема — повернемо item як дефолт (але тоді ensure_schema додасть item)
  return 'item';
}

/** Базові таблиці: складені тести + помилки + теорія */
function progress_ensure_schema(PDO $pdo): void {
  // 1) складені тести
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS user_passed_tests (
      user_id   TEXT NOT NULL,
      test_id   INT  NOT NULL,
      passed_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
      PRIMARY KEY (user_id, test_id)
    );
  ");

  // 2) помилки
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS user_test_mistakes (
      user_id     TEXT NOT NULL,
      test_id     INT  NOT NULL,
      question_id INT  NOT NULL,
      created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
      PRIMARY KEY (user_id, test_id, question_id)
    );
  ");

  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_mistakes_user ON user_test_mistakes(user_id);");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_mistakes_test ON user_test_mistakes(test_id);");

  // 3) теорія
  progress_ensure_theory_schema($pdo);
}

/**
 * Теорія:
 * У тебе в БД вже існує user_theory_done зі старою схемою (topic_key NOT NULL).
 * Тому ми:
 * - НЕ ламаємо існуючу таблицю/PK
 * - додаємо item/done_at якщо треба (але вставляємо в реальну key-колонку)
 */
function progress_ensure_theory_schema(PDO $pdo): void {
  // створимо таблицю тільки якщо її взагалі нема
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS user_theory_done (
      user_id TEXT NOT NULL,
      item    TEXT NOT NULL,
      done_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
      PRIMARY KEY (user_id, item)
    );
  ");

  // done_at якщо нема
  if (!progress_table_has_column($pdo, 'user_theory_done', 'done_at')) {
    $pdo->exec("ALTER TABLE user_theory_done ADD COLUMN IF NOT EXISTS done_at TIMESTAMPTZ NOT NULL DEFAULT NOW();");
  }

  // item як додаткова колонка (на випадок старих схем) — НЕ обов’язково, але корисно
  if (!progress_table_has_column($pdo, 'user_theory_done', 'item')) {
    $pdo->exec("ALTER TABLE user_theory_done ADD COLUMN IF NOT EXISTS item TEXT;");
  }

  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_theory_user ON user_theory_done(user_id);");
}

/**
 * Додає помилки (question IDs) до тесту.
 * ✅ FIX: дозволяємо testId=0 (екзамен/мікс).
 */
function progress_add_mistakes(string $uid, int $testId, array $qids): void {
  $uid = trim($uid);
  if ($uid === '') return;

  if ($testId < 0) $testId = 0; // bucket 0 дозволений

  // чистимо список
  $clean = [];
  foreach ($qids as $qid) {
    $qid = (int)$qid;
    if ($qid > 0) $clean[$qid] = true;
  }
  if (!$clean) return;

  $pdo = pdoi();

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

/** Позначає тест як “складений”. */
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

/** Повертає всі question_id, де користувач помилявся. */
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

/** Помилки по тестах: [ test_id => [question_id...] ] */
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

/* =======================================================================
   Compatibility layer: progress_user_get / progress_user_set
   ======================================================================= */

function progress_user_get(string $uid): array {
  $uid = trim($uid);
  if ($uid === '') return ['passed_tests' => [], 'theory_done' => []];

  $pdo = pdoi();

  // passed_tests
  $st = $pdo->prepare("SELECT test_id FROM user_passed_tests WHERE user_id = :u");
  $st->execute([':u' => $uid]);
  $passed = [];
  foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $tid) {
    $passed[(string)(int)$tid] = true;
  }

  // theory_done
  $keyCol = progress_pick_theory_key_column($pdo);
  $sql = "SELECT {$keyCol} AS k FROM user_theory_done WHERE user_id = :u";
  $st = $pdo->prepare($sql);
  $st->execute([':u' => $uid]);
  $theory = [];
  foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $it) {
    $it = trim((string)$it);
    if ($it === '') continue;
    $theory[$it] = true;
  }

  return [
    'passed_tests' => $passed,
    'theory_done'  => $theory,
  ];
}

function progress_user_set(string $uid, array $u): void {
  $uid = trim($uid);
  if ($uid === '') return;

  $pdo = pdoi();

  // 1) passed_tests
  if (isset($u['passed_tests']) && is_array($u['passed_tests'])) {
    foreach ($u['passed_tests'] as $testId => $flag) {
      if ($flag) {
        progress_mark_passed($uid, (int)$testId);
      }
    }
  }

  // 2) theory_done
  if (isset($u['theory_done']) && is_array($u['theory_done'])) {
    $keyCol = progress_pick_theory_key_column($pdo);

    // Підготовка insert під РЕАЛЬНУ схему (topic_key/item/...)
    $sql = "INSERT INTO user_theory_done (user_id, {$keyCol}) VALUES (:u, :k) ON CONFLICT DO NOTHING";
    $st = $pdo->prepare($sql);

    foreach ($u['theory_done'] as $key => $flag) {
      // У тебе в theory.php зараз зберігається масив ['done'=>true,'at'=>...]
      // Ми трактуємо це як "true"
      $isTrue = false;
      if (is_bool($flag)) $isTrue = $flag;
      elseif (is_array($flag)) $isTrue = !empty($flag['done']);
      else $isTrue = (bool)$flag;

      $k = trim((string)$key);
      if ($k === '' || !$isTrue) continue;

      $st->execute([':u' => $uid, ':k' => $k]);

      // якщо є колонка item — продублюємо для уніфікації (не обов’язково, але зручно)
      if ($keyCol !== 'item' && progress_table_has_column($pdo, 'user_theory_done', 'item')) {
        $st2 = $pdo->prepare("UPDATE user_theory_done SET item = COALESCE(NULLIF(item,''), :it) WHERE user_id=:u AND {$keyCol}=:k");
        $st2->execute([':u' => $uid, ':k' => $k, ':it' => $k]);
      }
    }
  }
}