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
 * Compatibility API (щоб не ламати старий код):
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

  // 3) теорія (compat)
  progress_ensure_theory_schema($pdo);
}

/**
 * Таблиця для “теорія пройдена”.
 * ✅ ВАЖЛИВО: якщо таблиця вже існує зі старими колонками — докручуємо.
 */
function progress_ensure_theory_schema(PDO $pdo): void {
  // створюємо “правильну” структуру, якщо таблиці нема
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS user_theory_done (
      user_id TEXT NOT NULL,
      item    TEXT NOT NULL,
      done_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
      PRIMARY KEY (user_id, item)
    );
  ");

  // якщо таблиця існувала раніше і там нема item — додаємо
  if (!progress_table_has_column($pdo, 'user_theory_done', 'item')) {
    // пробуємо додати item
    $pdo->exec("ALTER TABLE user_theory_done ADD COLUMN IF NOT EXISTS item TEXT;");

    // якщо є старі колонки — мігруємо дані в item
    $fallbacks = ['theory_id', 'theory_key', 'theory', 'key', 'slug', 'code'];
    foreach ($fallbacks as $col) {
      if (progress_table_has_column($pdo, 'user_theory_done', $col)) {
        // переносимо лише порожні item
        $pdo->exec("UPDATE user_theory_done SET item = CAST($col AS TEXT) WHERE item IS NULL OR item = '';");
        break;
      }
    }

    // якщо item досі null — ставимо заглушку щоб не падало
    $pdo->exec("UPDATE user_theory_done SET item = 'unknown' WHERE item IS NULL OR item = '';");
  }

  // якщо нема done_at — теж докрутимо (на всяк)
  if (!progress_table_has_column($pdo, 'user_theory_done', 'done_at')) {
    $pdo->exec("ALTER TABLE user_theory_done ADD COLUMN IF NOT EXISTS done_at TIMESTAMPTZ NOT NULL DEFAULT NOW();");
  }

  // індекс
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_theory_user ON user_theory_done(user_id);");

  // ⚠️ Якщо primary key старий/інший — ми його тут не ламаємо, щоб не ризикувати.
  // Головне: читання по item тепер працює.
}

/**
 * Додає помилки (question IDs) до тесту.
 * ✅ FIX: дозволяємо testId=0 (екзамен/мікс), раніше було <=0 і все ігнорувалось.
 */
function progress_add_mistakes(string $uid, int $testId, array $qids): void {
  $uid = trim($uid);
  if ($uid === '') return;

  // ✅ дозволяємо bucket 0
  if ($testId < 0) $testId = 0;

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

/* =======================================================================
   Compatibility layer: progress_user_get / progress_user_set
   ======================================================================= */

/**
 * Повертає прогрес у форматі, який очікує quiz.php:
 * [
 *   'passed_tests' => ['12'=>true, '13'=>true, ...],
 *   'theory_done'  => ['topic_x'=>true, ...]
 * ]
 */
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

  // theory_done (item може бути доданий міграцією)
  $st = $pdo->prepare("SELECT item FROM user_theory_done WHERE user_id = :u");
  $st->execute([':u' => $uid]);
  $theory = [];
  foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $it) {
    $it = (string)$it;
    if ($it === '') continue;
    $theory[$it] = true;
  }

  return [
    'passed_tests' => $passed,
    'theory_done'  => $theory,
  ];
}

/**
 * Приймає масив прогресу і синхронізує його в БД.
 */
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
    $st = $pdo->prepare("
      INSERT INTO user_theory_done (user_id, item)
      VALUES (:u, :i)
      ON CONFLICT DO NOTHING
    ");
    foreach ($u['theory_done'] as $item => $flag) {
      $item = trim((string)$item);
      if ($item === '' || !$flag) continue;
      $st->execute([':u' => $uid, ':i' => $item]);
    }
  }
}