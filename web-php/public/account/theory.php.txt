<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

/**
 * ProstoPDR — THEORY PAGE
 * - URL: /account/theory.php?topic=ЗАГАЛЬНІ%20ПОЛОЖЕННЯ
 * - Reads: /public/data/theory/{slug}.txt
 * - Saves "theory done": /storage/progress.json (fallback) OR your progress_user_set/get if exist
 * - No mbstring usage (fixes mb_strtolower error)
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/** -------------------- Helpers (safe fallbacks) -------------------- */
if (!function_exists('h')) {
    function h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
if (!function_exists('redirect')) {
    function redirect(string $to): void {
        header('Location: ' . $to);
        exit;
    }
}
if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
        return (string)$_SESSION['csrf'];
    }
}
if (!function_exists('csrf_verify')) {
    function csrf_verify(?string $token): void {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $ok = is_string($token) && isset($_SESSION['csrf']) && hash_equals((string)$_SESSION['csrf'], (string)$token);
        if (!$ok) {
            http_response_code(403);
            exit('CSRF verification failed');
        }
    }
}

/** -------------------- Detect logged in user id (robust) -------------------- */
function detect_uid_from_session(): string {
    // Most common variants
    $candidates = [
        $_SESSION['user']['id'] ?? null,
        $_SESSION['user']['user_id'] ?? null,
        $_SESSION['user_id'] ?? null,
        $_SESSION['uid'] ?? null,
        $_SESSION['auth']['id'] ?? null,
        $_SESSION['auth']['user_id'] ?? null,
        $_SESSION['account']['id'] ?? null,
        $_SESSION['account_id'] ?? null,
    ];

    foreach ($candidates as $v) {
        if (is_int($v)) return (string)$v;
        if (is_string($v) && trim($v) !== '') return trim($v);
    }

    // As last resort: if you store email only, use email as key
    $emailCandidates = [
        $_SESSION['user']['email'] ?? null,
        $_SESSION['email'] ?? null,
        $_SESSION['auth']['email'] ?? null,
    ];
    foreach ($emailCandidates as $e) {
        if (is_string($e) && trim($e) !== '') return 'email:' . trim($e);
    }

    return '';
}

$uid = detect_uid_from_session();
if ($uid === '') {
    // If you have your own auth redirect function, keep consistent; otherwise go to login
    redirect('/login');
}

$csrf = csrf_token();

/** -------------------- Slugify UA/RU to ASCII (NO mbstring) -------------------- */
function slugify_ua(string $s): string {
    $s = trim($s);
    if ($s === '') return '';

    $map = [
        'А'=>'A','Б'=>'B','В'=>'V','Г'=>'H','Ґ'=>'G','Д'=>'D','Е'=>'E','Є'=>'Ye','Ж'=>'Zh','З'=>'Z','И'=>'Y','І'=>'I','Ї'=>'Yi','Й'=>'Y','К'=>'K','Л'=>'L','М'=>'M','Н'=>'N','О'=>'O','П'=>'P','Р'=>'R','С'=>'S','Т'=>'T','У'=>'U','Ф'=>'F','Х'=>'Kh','Ц'=>'Ts','Ч'=>'Ch','Ш'=>'Sh','Щ'=>'Shch','Ю'=>'Yu','Я'=>'Ya',
        'а'=>'a','б'=>'b','в'=>'v','г'=>'h','ґ'=>'g','д'=>'d','е'=>'e','є'=>'ye','ж'=>'zh','з'=>'z','и'=>'y','і'=>'i','ї'=>'yi','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'kh','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'shch','ю'=>'yu','я'=>'ya',
        'Ъ'=>'','ъ'=>'','Ы'=>'y','ы'=>'y','Э'=>'e','э'=>'e','Ё'=>'yo','ё'=>'yo',
        'Ь'=>'','ь'=>'',
        '’'=>'', '\''=>'',
        '«'=>'', '»'=>'',
    ];

    $s = strtr($s, $map);
    $s = preg_replace('~[^a-zA-Z0-9]+~', '-', $s);
    $s = trim((string)$s, '-');
    $s = strtolower($s);
    return $s;
}

/** -------------------- Progress storage (uses your lib if exists) -------------------- */
$progressLib = __DIR__ . '/../../src/progress.php';
if (is_file($progressLib)) {
    require_once $progressLib;
}

function progress_read_all_fallback(string $path): array {
    if (!is_file($path)) return ['users' => []];
    $raw = file_get_contents($path);
    if (!is_string($raw)) return ['users' => []];
    if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);
    $data = json_decode($raw, true);
    if (!is_array($data)) return ['users' => []];
    if (!isset($data['users']) || !is_array($data['users'])) $data['users'] = [];
    return $data;
}

function progress_write_all_fallback(string $path, array $data): void {
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0777, true);

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) return;

    $tmp = $path . '.tmp';
    file_put_contents($tmp, $json);
    @rename($tmp, $path);
}

function user_progress_get(string $uid): array {
    if (function_exists('progress_user_get')) {
        $u = progress_user_get($uid);
        if (!is_array($u)) $u = [];
        return $u;
    }
    $p = __DIR__ . '/../../storage/progress.json';
    $all = progress_read_all_fallback($p);
    $u = $all['users'][$uid] ?? [];
    if (!is_array($u)) $u = [];
    return $u;
}

function user_progress_set(string $uid, array $u): void {
    if (function_exists('progress_user_set')) {
        progress_user_set($uid, $u);
        return;
    }
    $p = __DIR__ . '/../../storage/progress.json';
    $all = progress_read_all_fallback($p);
    if (!isset($all['users']) || !is_array($all['users'])) $all['users'] = [];
    $u['updated_at'] = date('c');
    $all['users'][$uid] = $u;
    progress_write_all_fallback($p, $all);
}

function theory_is_done(string $uid, string $topic): bool {
    $u = user_progress_get($uid);
    $td = $u['theory_done'] ?? [];
    if (!is_array($td)) return false;
    $x = $td[$topic] ?? null;
    return is_array($x) && !empty($x['done']);
}

function theory_mark_done(string $uid, string $topic): void {
    $u = user_progress_get($uid);
    if (!isset($u['theory_done']) || !is_array($u['theory_done'])) $u['theory_done'] = [];
    $u['theory_done'][$topic] = ['done' => true, 'at' => date('c')];
    user_progress_set($uid, $u);
}

/** -------------------- Input topic -------------------- */
$topic = (string)($_GET['topic'] ?? '');
$topic = trim($topic);
if ($topic === '') {
    http_response_code(404);
    echo 'Topic not provided';
    exit;
}
$topicSlug = slugify_ua($topic);
if ($topicSlug === '') {
    http_response_code(404);
    echo 'Invalid topic';
    exit;
}

/** -------------------- Resolve theory file -------------------- */
$theoryDir = __DIR__ . '/../data/theory';
$theoryFile = $theoryDir . '/' . $topicSlug . '.txt';
$theoryText = '';

if (is_file($theoryFile)) {
    $raw = file_get_contents($theoryFile);
    if (is_string($raw)) {
        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);
        $theoryText = $raw;
    }
}

/** -------------------- Find first test_id by topic (from tests_export.json) -------------------- */
function first_test_id_for_topic(string $topic): int {
    $path = __DIR__ . '/../data/tests_export.json';
    if (!is_file($path)) return 0;
    $raw = file_get_contents($path);
    if (!is_string($raw)) return 0;
    if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);
    $arr = json_decode($raw, true);
    if (!is_array($arr)) return 0;

    foreach ($arr as $it) {
        if (!is_array($it)) continue;
        if (($it['type'] ?? '') !== 'test') continue;
        if ((string)($it['topic'] ?? '') !== $topic) continue;
        $id = (int)($it['id'] ?? 0);
        if ($id > 0) return $id;
    }
    return 0;
}

$firstTestId = first_test_id_for_topic($topic);

/** -------------------- POST: confirm theory -------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf'] ?? null);

    $action = (string)($_POST['action'] ?? '');
    if ($action === 'confirm') {
        theory_mark_done($uid, $topic);
        $qs = 'topic=' . rawurlencode($topic) . '&done=1';
        if ($firstTestId > 0) $qs .= '&go_test_id=' . $firstTestId;
        redirect('/account/theory.php?' . $qs);
    }

    redirect('/account/theory.php?topic=' . rawurlencode($topic));
}

/** -------------------- Done screen -------------------- */
$doneFlag = (string)($_GET['done'] ?? '');
$goTestId = (int)($_GET['go_test_id'] ?? 0);

if ($doneFlag === '1') {
    $isDone = theory_is_done($uid, $topic);
    ?>
    <!doctype html>
    <html lang="uk">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= h($topic) ?> — Теорія</title>
        <style>
            body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#fff}
            .wrap{max-width:1100px;margin:0 auto;padding:64px 16px}
            .card{background:#fff;border-radius:18px;box-shadow:0 10px 35px rgba(0,0,0,.08);padding:28px}
            .title{font-size:28px;margin:0;font-weight:900}
            .row{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
            .pill{background:#f1f3f7;border-radius:999px;padding:10px 12px;font-weight:800}
            .actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
            .btn{display:inline-block;padding:12px 14px;border-radius:14px;background:#0e2d23;color:#fff;text-decoration:none;font-weight:900;border:0;cursor:pointer}
            .btn2{display:inline-block;padding:12px 14px;border-radius:14px;background:#f1f3f7;color:#111;text-decoration:none;font-weight:900}
            .ok{color:#0a7a3d}
            .bad{color:#b42318}
        </style>
    </head>
    <body>
    <div class="wrap">
        <div class="card">
            <h1 class="title"><?= h($topic) ?></h1>
            <div class="row">
                <div class="pill">
                    Статус:
                    <b class="<?= $isDone ? 'ok' : 'bad' ?>">
                        <?= $isDone ? 'Ознайомлений з теорією' : 'Не підтверджено' ?>
                    </b>
                </div>
            </div>

            <div class="actions">
                <a class="btn2" href="/account/index.php">В кабінет</a>

                <?php if ($goTestId > 0): ?>
                    <form method="post" action="/account/quiz.php" style="margin:0">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="start">
                        <input type="hidden" name="mode" value="test">
                        <input type="hidden" name="test_id" value="<?= (int)$goTestId ?>">
                        <button class="btn" type="submit">Перейти до тестування →</button>
                    </form>
                <?php else: ?>
                    <a class="btn" href="/account/tests.php">Перейти до тестування →</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

/** -------------------- Normal theory page -------------------- */
$isDone = theory_is_done($uid, $topic);
?>
<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($topic) ?> — Теорія</title>
    <style>
        body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#fff}
        .wrap{max-width:1100px;margin:0 auto;padding:46px 16px 64px}
        .card{background:#fff;border-radius:18px;box-shadow:0 10px 35px rgba(0,0,0,.08);padding:22px}
        .h1{margin:0;font-size:28px;font-weight:900}
        .sub{margin-top:6px;opacity:.75;font-weight:700}
        .pill{display:inline-flex;align-items:center;gap:8px;background:#f1f3f7;border-radius:999px;padding:8px 12px;font-weight:900;margin-top:12px}
        .ok{color:#0a7a3d}
        .text{margin-top:14px;line-height:1.55;font-size:16px;white-space:pre-wrap}
        .actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
        .btn{display:inline-block;padding:12px 14px;border-radius:14px;background:#0e2d23;color:#fff;text-decoration:none;font-weight:900;border:0;cursor:pointer}
        .btn2{display:inline-block;padding:12px 14px;border-radius:14px;background:#f1f3f7;color:#111;text-decoration:none;font-weight:900}
        .warn{opacity:.75}
        code{background:#f1f3f7;padding:2px 6px;border-radius:8px}
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1 class="h1"><?= h($topic) ?></h1>
        <div class="sub">Теоретичний матеріал</div>

        <?php if ($isDone): ?>
            <div class="pill"><span class="ok">✔</span> Ознайомлений з теорією</div>
        <?php endif; ?>

        <?php if ($theoryText === ''): ?>
            <div class="text warn">
                Теорія для цієї теми ще не додана.<br>
                Очікуваний файл: <code><?= h($theoryFile) ?></code>
            </div>
        <?php else: ?>
            <div class="text"><?= h($theoryText) ?></div>
        <?php endif; ?>

        <div class="actions">
            <a class="btn2" href="/account/tests.php">← Назад до тестів</a>
            <a class="btn2" href="/account/index.php">В кабінет</a>

            <form method="post" action="/account/theory.php?topic=<?= h(rawurlencode($topic)) ?>" style="margin:0">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="confirm">
                <button class="btn" type="submit">Ознайомлений</button>
            </form>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../partials/chat_widget.php'; ?>
</body>
</html>