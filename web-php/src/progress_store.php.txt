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
 * Compatibility API:
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

function progress_ensure_schema(PDO $pdo): void {
  // passed tests
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS user_passed_tests (
      user_id   TEXT NOT NULL,
      test_id   INT  NOT NULL,
      passed_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
      PRIMARY KEY (user_id, test_id)
    );
  ");

  // mistakes
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

  // theory (do not break existing schema)
  progress_ensure_theory_schema($pdo);
}

/**
 * ВАЖЛИВО:
 * У тебе user_theory_done вже існує зі старою схемою (topic_key NOT NULL).
 * Ми НЕ ламаємо таблицю, а лише гарантуємо, що є done_at, і (за бажанням) item.
 */
function progress_ensure_theory_schema(PDO $pdo): void {
  // якщо таблиці нема — створимо базову (item), але якщо вона є — Postgres просто пропустить
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS user_theory_done (
      user_id TEXT NOT NULL,
      item    TEXT NOT NULL,
      done_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
      PRIMARY KEY (user_id, item)
    );
  ");

  // done_at must exist (many old schemas have it, but just in case)
  if (!progress_table_has_column($pdo, 'user_theory_done', 'done_at')) {
    $pdo->exec("ALTER TABLE user_theory_done ADD COLUMN IF NOT EXISTS done_at TIMESTAMPTZ NOT NULL DEFAULT NOW();");
  }

  // item as optional compatibility column (if missing)
  if (!progress_table_has_column($pdo, 'user_theory_done', 'item')) {
    $pdo->exec("ALTER TABLE user_theory_done ADD COLUMN IF NOT EXISTS item TEXT;");
  }

  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_theory_user ON user_theory_done(user_id);");
}

/**
 * Визначає, яка колонка в user_theory_done є ключем теми.
 * У тебе це topic_key (NOT NULL), тому вона має бути першою в пріоритеті.
 */
function progress_theory_key_col(PDO $pdo): string {
  $candidates = [
    'topic_key',   // ✅ твій випадок
    'item',
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
  // fallback
  return 'topic_key';
}

/**
 * Нормалізує theory_done у список рядків-тем.
 * Підтримує всі формати: map, list, list of objects.
 */
function progress_extract_theory_topics($theory_done): array {
  $topics = [];

  if (!is_array($theory_done)) return [];

  foreach ($theory_done as $k => $v) {
    // 1) Map: ['ТЕМА'=>true] або ['ТЕМА'=>['done'=>true]]
    if (is_string($k) && trim($k) !== '' && !is_int($k)) {
      $flag = $v;

      $isTrue = false;
      if (is_bool($flag)) $isTrue = $flag;
      elseif (is_array($flag)) $isTrue = !empty($flag['done']) || !empty($flag['ok']) || !empty($flag['passed']);
      else $isTrue = (bool)$flag;

      if ($isTrue) {
        $topics[] = trim($k);
      }
      continue;
    }

    // 2) List: ['ТЕМА1','ТЕМА2']
    if (is_string($v) && trim($v) !== '') {
      $topics[] = trim($v);
      continue;
    }

    // 3) List of objects: [ ['topic'=>'ТЕМА','done'=>true], ... ]
    if (is_array($v)) {
      $topic = '';
      if (isset($v['topic']) && is_string($v['topic'])) $topic = trim($v['topic']);
      elseif (isset($v['topic_key']) && is_string($v['topic_key'])) $topic = trim($v['topic_key']);
      elseif (isset($v['item']) && is_string($v['item'])) $topic = trim($v['item']);
      elseif (isset($v['key']) && is_string($v['key'])) $topic = trim($v['key']);
      elseif (isset($v['slug']) && is_string($v['slug'])) $topic = trim($v['slug']);

      $flag = $v['done'] ?? $v['ok'] ?? $v['passed'] ?? true;
      $isTrue = is_bool($flag) ? $flag : (bool)$flag;

      if ($topic !== '' && $isTrue) {
        $topics[] = $topic;
      }
      continue;
    }
  }

  // унікальні
  $out = [];
  foreach ($topics as $t) {
    $t = trim((string)$t);
    if ($t !== '') $out[$t] = true;
  }
  return array_keys($out);
}

/**
 * Додає помилки.
 * ✅ FIX: дозволяємо testId=0 (екзамен/мікс).
 */
function progress_add_mistakes(string $uid, int $testId, array $qids): void {
  $uid = trim($uid);
  if ($uid === '') return;

  if ($testId < 0) $testId = 0;

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

/** Складений тест */
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

/** Всі помилки */
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

/** Помилки по тестах */
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
   Compatibility: progress_user_get / progress_user_set
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
  $keyCol = progress_theory_key_col($pdo);
  $st = $pdo->prepare("SELECT {$keyCol} AS k FROM user_theory_done WHERE user_id = :u");
  $st->execute([':u' => $uid]);

  $theory = [];
  foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $k) {
    $k = trim((string)$k);
    if ($k === '') continue;
    $theory[$k] = true;
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
      if ($flag) progress_mark_passed($uid, (int)$testId);
    }
  }

  // 2) theory_done
  if (isset($u['theory_done'])) {
    $topics = progress_extract_theory_topics($u['theory_done']);
    if ($topics) {
      $keyCol = progress_theory_key_col($pdo);

      // Будуємо insert так, щоб НЕ було null в topic_key
      // Якщо є done_at — дамо NOW()
      $hasDoneAt = progress_table_has_column($pdo, 'user_theory_done', 'done_at');
      $cols = $hasDoneAt ? "user_id, {$keyCol}, done_at" : "user_id, {$keyCol}";
      $vals = $hasDoneAt ? ":u, :k, NOW()" : ":u, :k";

      $sql = "INSERT INTO user_theory_done ({$cols}) VALUES ({$vals}) ON CONFLICT DO NOTHING";
      $st = $pdo->prepare($sql);

      foreach ($topics as $topic) {
        $topic = trim((string)$topic);
        if ($topic === '') continue; // ✅ ніколи не вставляємо пусте

        $st->execute([':u' => $uid, ':k' => $topic]);

        // якщо є колонка item і вона не ключова — дублюємо
        if ($keyCol !== 'item' && progress_table_has_column($pdo, 'user_theory_done', 'item')) {
          $st2 = $pdo->prepare("UPDATE user_theory_done SET item = COALESCE(NULLIF(item,''), :it) WHERE user_id=:u AND {$keyCol}=:k");
          $st2->execute([':u' => $uid, ':k' => $topic, ':it' => $topic]);
        }
      }
    }
  }
}