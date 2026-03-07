<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function progress_debug_log(string $label, array $data = []): void
{
    $dir = dirname(__DIR__) . '/storage';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    $file = $dir . '/progress_debug.log';

    $line = '[' . date('Y-m-d H:i:s') . '] ' . $label;

    if (!empty($data)) {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            $line .= ' ' . $json;
        }
    }

    $line .= PHP_EOL;
    @file_put_contents($file, $line, FILE_APPEND);
}
function progress_db(): PDO
{
    $pdo = db();
    progress_ensure_schema($pdo);
    return $pdo;
}

function progress_ensure_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_passed_tests (
            user_id    TEXT NOT NULL,
            test_id    INTEGER NOT NULL,
            passed_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            PRIMARY KEY (user_id, test_id)
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_test_mistakes (
            user_id     TEXT NOT NULL,
            test_id     INTEGER NOT NULL DEFAULT 0,
            question_id INTEGER NOT NULL,
            created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_mistakes (
            user_id     TEXT NOT NULL,
            test_bucket INTEGER NOT NULL DEFAULT 0,
            question_id INTEGER NOT NULL,
            created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_theory_done (
            user_id    TEXT NOT NULL,
            topic_key  TEXT NOT NULL,
            done_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            PRIMARY KEY (user_id, topic_key)
        );
    ");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_passed_tests_user_id ON user_passed_tests(user_id);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_test_mistakes_user_id ON user_test_mistakes(user_id);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_test_mistakes_qid ON user_test_mistakes(question_id);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_mistakes_user_id ON user_mistakes(user_id);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_theory_done_user_id ON user_theory_done(user_id);");
}

function progress_slugify_topic(string $s): string
{
    $s = trim($s);
    if ($s === '') return '';

    if (function_exists('slugify_ua')) {
        $slug = (string)slugify_ua($s);
        if ($slug !== '') return $slug;
    }

    $map = [
        'а'=>'a','б'=>'b','в'=>'v','г'=>'h','ґ'=>'g','д'=>'d','е'=>'e','є'=>'ye','ж'=>'zh','з'=>'z',
        'и'=>'y','і'=>'i','ї'=>'yi','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p',
        'р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'kh','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'shch',
        'ю'=>'yu','я'=>'ya','ь'=>'','\''=>'',
    ];

    $s = mb_strtolower($s, 'UTF-8');
    $out = '';
    $len = mb_strlen($s, 'UTF-8');

    for ($i = 0; $i < $len; $i++) {
        $ch = mb_substr($s, $i, 1, 'UTF-8');
        if (isset($map[$ch])) {
            $out .= $map[$ch];
        } elseif (preg_match('/[a-z0-9]/u', $ch)) {
            $out .= $ch;
        } else {
            $out .= '-';
        }
    }

    $out = preg_replace('~-+~', '-', $out);
    $out = trim((string)$out, '-');

    return $out;
}

function progress_user_get(string $uid): array
{
    $uid = trim($uid);
    if ($uid === '') 
    progress_debug_log('progress_user_get:enter', [
    'uid' => $uid, {
        return [
            'passed_tests'   => [],
            'theory_done'    => [],
            'mistakes'       => [],
            'mistakes_ids'   => [],
            'mistakes_count' => 0,
            'test_mistakes'  => [],
        ];
    }
    progress_debug_log('progress_user_get:return', [
    'uid' => $uid,
    'passed_tests_count' => count($passedTests),
    'theory_done_count' => count($theoryDone),
    'mistake_ids_count' => count($mistakeIds),
    'mistake_ids_sample' => array_slice($mistakeIds, 0, 20),
    'test_mistakes_buckets' => array_keys($testMistakes),
]); 
    $pdo = progress_db();

    $passedTests = [];
    $st = $pdo->prepare("
        SELECT test_id, passed_at
        FROM user_passed_tests
        WHERE user_id = :uid
    ");
    $st->execute([':uid' => $uid]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $tid = (int)($row['test_id'] ?? 0);
        if ($tid > 0) {
            $passedTests[(string)$tid] = (string)($row['passed_at'] ?? '');
        }
    }

    $theoryDone = [];
    $st = $pdo->prepare("
        SELECT topic_key, done_at
        FROM user_theory_done
        WHERE user_id = :uid
    ");
    $st->execute([':uid' => $uid]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = trim((string)($row['topic_key'] ?? ''));
        if ($key !== '') {
            $theoryDone[$key] = [
                'done'    => true,
                'done_at' => (string)($row['done_at'] ?? ''),
            ];
        }
    }

    $mistakeIdsSet = [];
    $testMistakes = [];

    $st = $pdo->prepare("
        SELECT test_id, question_id, created_at
        FROM user_test_mistakes
        WHERE user_id = :uid
        ORDER BY created_at DESC
    ");
    $st->execute([':uid' => $uid]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $testId = (int)($row['test_id'] ?? 0);
        $qid    = (int)($row['question_id'] ?? 0);
        $at     = (string)($row['created_at'] ?? '');
        if ($qid <= 0) continue;

        $mistakeIdsSet[$qid] = true;

        $bucket = (string)$testId;
        if (!isset($testMistakes[$bucket])) {
            $testMistakes[$bucket] = [];
        }
        if (!isset($testMistakes[$bucket][$qid])) {
            $testMistakes[$bucket][$qid] = $at;
        }
    }

    // fallback/legacy table
    $st = $pdo->prepare("
        SELECT test_bucket, question_id, created_at
        FROM user_mistakes
        WHERE user_id = :uid
        ORDER BY created_at DESC
    ");
    $st->execute([':uid' => $uid]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $testId = (int)($row['test_bucket'] ?? 0);
        $qid    = (int)($row['question_id'] ?? 0);
        $at     = (string)($row['created_at'] ?? '');
        if ($qid <= 0) continue;

        $mistakeIdsSet[$qid] = true;

        $bucket = (string)$testId;
        if (!isset($testMistakes[$bucket])) {
            $testMistakes[$bucket] = [];
        }
        if (!isset($testMistakes[$bucket][$qid])) {
            $testMistakes[$bucket][$qid] = $at;
        }
    }

    $mistakeIds = array_map('intval', array_keys($mistakeIdsSet));
    sort($mistakeIds);

    return [
        'passed_tests'   => $passedTests,
        'theory_done'    => $theoryDone,

        // універсально для старого/нового коду
        'mistakes'       => [
            'ids'      => $mistakeIds,
            'count'    => count($mistakeIds),
            'by_test'  => $testMistakes,
        ],
        'mistakes_ids'   => $mistakeIds,
        'mistakes_count' => count($mistakeIds),
        'test_mistakes'  => $testMistakes,
    ];
}

function progress_user_set(string $uid, array $u): void
{
    // лишаємо для сумісності, але нічого не робимо,
    // бо джерело істини тепер Postgres-таблиці.
}

function progress_add_mistakes(string $uid, int $testId, array $qids): void
{
    $uidRaw = $uid;
    $uid = trim($uid);

    progress_debug_log('progress_add_mistakes:enter', [
        'uid_raw' => $uidRaw,
        'uid_trimmed' => $uid,
        'test_id' => $testId,
        'qids_raw' => $qids,
    ]);

    if ($uid === '') {
        progress_debug_log('progress_add_mistakes:skip_empty_uid', [
            'uid_raw' => $uidRaw,
            'test_id' => $testId,
        ]);
        return;
    }

    $qids = array_values(array_unique(array_map('intval', $qids)));
    $qids = array_values(array_filter($qids, fn($v) => $v > 0));

    progress_debug_log('progress_add_mistakes:normalized', [
        'uid' => $uid,
        'test_id' => $testId,
        'qids' => $qids,
    ]);

    if (!$qids) {
        progress_debug_log('progress_add_mistakes:skip_empty_qids', [
            'uid' => $uid,
            'test_id' => $testId,
        ]);
        return;
    }

    try {
        $pdo = progress_db();

        progress_debug_log('progress_add_mistakes:db_ok', [
            'uid' => $uid,
            'test_id' => $testId,
            'db_class' => get_class($pdo),
        ]);

        $ins1 = $pdo->prepare("
            INSERT INTO user_test_mistakes (user_id, test_id, question_id)
            VALUES (:uid, :test_id, :qid)
        ");

        $ins2 = $pdo->prepare("
            INSERT INTO user_mistakes (user_id, test_bucket, question_id)
            VALUES (:uid, :test_bucket, :qid)
        ");

        foreach ($qids as $qid) {
            $ins1->execute([
                ':uid'     => $uid,
                ':test_id' => $testId,
                ':qid'     => $qid,
            ]);

            $ins2->execute([
                ':uid'         => $uid,
                ':test_bucket' => $testId,
                ':qid'         => $qid,
            ]);

            progress_debug_log('progress_add_mistakes:inserted_one', [
                'uid' => $uid,
                'test_id' => $testId,
                'qid' => $qid,
            ]);
        }

        $check1 = $pdo->prepare("
            SELECT COUNT(*)::int AS c
            FROM user_test_mistakes
            WHERE user_id = :uid
        ");
        $check1->execute([':uid' => $uid]);
        $row1 = $check1->fetch(PDO::FETCH_ASSOC);

        $check2 = $pdo->prepare("
            SELECT COUNT(*)::int AS c
            FROM user_mistakes
            WHERE user_id = :uid
        ");
        $check2->execute([':uid' => $uid]);
        $row2 = $check2->fetch(PDO::FETCH_ASSOC);

        progress_debug_log('progress_add_mistakes:after_insert_counts', [
            'uid' => $uid,
            'test_id' => $testId,
            'user_test_mistakes_count' => (int)($row1['c'] ?? 0),
            'user_mistakes_count' => (int)($row2['c'] ?? 0),
        ]);
    } catch (Throwable $e) {
        progress_debug_log('progress_add_mistakes:ERROR', [
            'uid' => $uid,
            'test_id' => $testId,
            'qids' => $qids,
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
    }
}

function progress_remove_mistakes(string $uid, array $qids): void
{
    $uid = trim($uid);
    if ($uid === '') return;

    $qids = array_values(array_unique(array_map('intval', $qids)));
    $qids = array_values(array_filter($qids, fn($v) => $v > 0));
    if (!$qids) return;

    $pdo = progress_db();

    $placeholders = implode(',', array_fill(0, count($qids), '?'));

    $sql1 = "DELETE FROM user_test_mistakes WHERE user_id = ? AND question_id IN ($placeholders)";
    $sql2 = "DELETE FROM user_mistakes WHERE user_id = ? AND question_id IN ($placeholders)";

    $params = array_merge([$uid], $qids);

    $st = $pdo->prepare($sql1);
    $st->execute($params);

    $st = $pdo->prepare($sql2);
    $st->execute($params);
}

function progress_mark_passed(string $uid, int $testId): void
{
    $uid = trim($uid);
    if ($uid === '' || $testId <= 0) return;

    $pdo = progress_db();
    $st = $pdo->prepare("
        INSERT INTO user_passed_tests (user_id, test_id)
        VALUES (:uid, :test_id)
        ON CONFLICT (user_id, test_id) DO UPDATE SET passed_at = NOW()
    ");
    $st->execute([
        ':uid'     => $uid,
        ':test_id' => $testId,
    ]);
}

function progress_all_mistakes_ids(string $uid): array
{
    $u = progress_user_get($uid);
    $ids = $u['mistakes_ids'] ?? [];
    if (!is_array($ids)) return [];

    $ids = array_values(array_unique(array_map('intval', $ids)));
    $ids = array_values(array_filter($ids, fn($v) => $v > 0));
    sort($ids);

    return $ids;
}

function progress_mistakes_count(string $uid): int
{
    return count(progress_all_mistakes_ids($uid));
}

function progress_mark_theory_done(string $uid, string $topicKey): void
{
    $uid = trim($uid);
    $topicKey = trim($topicKey);

    if ($uid === '' || $topicKey === '') return;

    $pdo = progress_db();

    $keys = [$topicKey];
    $slug = progress_slugify_topic($topicKey);
    if ($slug !== '' && !in_array($slug, $keys, true)) {
        $keys[] = $slug;
    }

    $st = $pdo->prepare("
        INSERT INTO user_theory_done (user_id, topic_key)
        VALUES (:uid, :topic_key)
        ON CONFLICT (user_id, topic_key) DO UPDATE SET done_at = NOW()
    ");

    foreach ($keys as $key) {
        $st->execute([
            ':uid'      => $uid,
            ':topic_key'=> $key,
        ]);
    }
}