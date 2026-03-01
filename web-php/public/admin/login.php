<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Безпечний helper
|--------------------------------------------------------------------------
*/
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/*
|--------------------------------------------------------------------------
| ВЛАСНИЙ CSRF ДЛЯ АДМІН-ЛОГІНУ
|--------------------------------------------------------------------------
*/
function admin_login_csrf_token(): string {
    if (empty($_SESSION['admin_login_csrf']) || !is_string($_SESSION['admin_login_csrf'])) {
        $_SESSION['admin_login_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['admin_login_csrf'];
}

function admin_login_csrf_verify(?string $token): void {
    $sess = (string)($_SESSION['admin_login_csrf'] ?? '');
    $tok  = (string)$token;

    if ($sess === '' || $tok === '' || !hash_equals($sess, $tok)) {
        http_response_code(419);
        echo "CSRF token invalid";
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Читання ADMIN_KEY
|--------------------------------------------------------------------------
*/
function admin_read_env_file_value(string $key, string $envPath): string {
    if (!is_file($envPath)) return '';

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) return '';

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;

        $pos = strpos($line, '=');
        if ($pos === false) continue;

        $k = trim(substr($line, 0, $pos));
        if ($k !== $key) continue;

        $v = trim(substr($line, $pos + 1));

        if (strlen($v) >= 2) {
            $first = $v[0];
            $last  = $v[strlen($v) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $v = substr($v, 1, -1);
            }
        }

        return trim($v);
    }

    return '';
}

function admin_get_admin_key(): string {

    // 1) getenv
    $v = getenv('ADMIN_KEY');
    if (is_string($v) && trim($v) !== '') {
        $v = trim($v);

        if (strlen($v) >= 2) {
            $first = $v[0];
            $last  = $v[strlen($v) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $v = substr($v, 1, -1);
            }
        }

        return trim($v);
    }

    // 2) fallback — читаємо .env вручну
    $envPath = __DIR__ . '/../../.env';
    $fromFile = admin_read_env_file_value('ADMIN_KEY', $envPath);

    return $fromFile;
}

/*
|--------------------------------------------------------------------------
| Обробка POST
|--------------------------------------------------------------------------
*/
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    admin_login_csrf_verify($_POST['csrf'] ?? null);

    $inputKey = trim((string)($_POST['key'] ?? ''));
    $realKey  = admin_get_admin_key();

    if ($realKey !== '' && hash_equals($realKey, $inputKey)) {

        $_SESSION['is_admin'] = true;
        $_SESSION['admin_logged'] = true;

        // CSRF для адмін-панелі
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(16));

        header('Location: /admin/users.php', true, 302);
        exit;
    }

    $error = 'Невірний ключ.';
}

$csrf = admin_login_csrf_token();

?>
<!doctype html>
<html lang="uk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin Login — ProstoPDR</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Unbounded:wght@500;700&display=swap" rel="stylesheet">

<link rel="stylesheet" href="/assets/css/style.css?v=4">
</head>

<body>

<main class="section section--soft" style="padding-top:46px;">
  <div class="container" style="max-width:560px;">
    <div class="account-card">

      <h1 class="h2">Admin</h1>
      <p class="lead">Вхід в адмін-панель.</p>

      <?php if ($error !== ''): ?>
        <div class="notice notice--bad" style="margin-bottom:12px;">
          <?= h($error) ?>
        </div>
      <?php endif; ?>

      <form method="post" action="/admin/login.php">

        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

        <label style="display:block; font-weight:800; margin-bottom:6px;">
          ADMIN_KEY
        </label>

        <input
          class="input"
          type="password"
          name="key"
          placeholder="Введи ключ"
          required
          style="width:100%; padding:12px; border-radius:12px; border:1px solid rgba(0,0,0,.12);"
        >

        <div style="height:12px;"></div>

        <div style="display:flex; gap:10px; flex-wrap:wrap;">
          <button class="btn btn--primary" type="submit">
            Увійти
          </button>

          <a class="btn btn--ghost" href="/">
            На головну
          </a>
        </div>

      </form>

    </div>
  </div>
</main>

</body>
</html>