<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
  @session_start();
}

/**
 * ===========================
 * INLINE CHAT API (same file)
 * ===========================
 * Endpoints:
 *   api('/chat_api.php?action=list', { method:'GET' })
     api('/chat_api.php?action=fetch' + qs, { method:'GET' })
     api('/chat_api.php?action=send' + qs, { method:'POST', body: JSON.stringify({ text }) })
 *
 * Storage:
 *   /data/chat_threads/<threadId>.json
 *
 * Admin mode:
 *   $_SESSION['is_admin'] === true
 */
if (isset($_GET['chat_api']) && (string)$_GET['chat_api'] === '1') {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

  $isAdmin = !empty($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;

  // thread id for user: user_id if authed, else session id
  $isAuthedApi = !empty($_SESSION['user_id']);
  $defaultThread = $isAuthedApi ? ('u_' . (string)$_SESSION['user_id']) : ('g_' . session_id());

  $action = (string)($_GET['action'] ?? '');
  if ($action === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing action'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $baseDir = __DIR__ . '/data/chat_threads';
  if (!is_dir($baseDir)) {
    @mkdir($baseDir, 0775, true);
  }

  $safeThread = function (string $thread): string {
    // allow only a-zA-Z0-9 _ -
    $thread = preg_replace('/[^a-zA-Z0-9_\-]/', '', $thread) ?? '';
    if ($thread === '') {
      $thread = 'invalid';
    }
    return $thread;
  };

  $threadId = $defaultThread;
  if ($isAdmin && isset($_GET['thread'])) {
    $threadId = (string)$_GET['thread'];
  }
  $threadId = $safeThread($threadId);

  $threadFile = $baseDir . '/' . $threadId . '.json';

  $readThread = function (string $file): array {
    if (!is_file($file)) return ['thread' => null, 'messages' => [], 'meta' => []];
    $raw = @file_get_contents($file);
    if (!is_string($raw) || $raw === '') return ['thread' => null, 'messages' => [], 'meta' => []];
    $data = json_decode($raw, true);
    if (!is_array($data)) return ['thread' => null, 'messages' => [], 'meta' => []];
    if (!isset($data['messages']) || !is_array($data['messages'])) $data['messages'] = [];
    if (!isset($data['meta']) || !is_array($data['meta'])) $data['meta'] = [];
    return $data;
  };

  $writeThread = function (string $file, array $data): bool {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (!is_string($json)) return false;
    $tmp = $file . '.tmp';
    $ok = @file_put_contents($tmp, $json, LOCK_EX);
    if ($ok === false) return false;
    return @rename($tmp, $file);
  };

  $listThreads = function (string $dir): array {
    if (!is_dir($dir)) return [];
    $files = glob($dir . '/*.json');
    if (!is_array($files)) return [];
    $threads = [];
    foreach ($files as $f) {
      $bn = basename($f, '.json');
      $mtime = @filemtime($f);
      $threads[] = [
        'thread' => $bn,
        'updated_at' => is_int($mtime) ? $mtime : 0,
      ];
    }
    usort($threads, static function ($a, $b) {
      return ($b['updated_at'] ?? 0) <=> ($a['updated_at'] ?? 0);
    });
    return $threads;
  };

  $now = time();

  if ($action === 'list') {
    if (!$isAdmin) {
      http_response_code(403);
      echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    echo json_encode(['ok' => true, 'threads' => $listThreads($baseDir)], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'fetch') {
    $data = $readThread($threadFile);

    // Ensure meta exists
    if (!isset($data['meta']['created_at'])) $data['meta']['created_at'] = $now;
    if (!isset($data['meta']['updated_at'])) $data['meta']['updated_at'] = $now;

    // For regular user, do not allow reading чужих thread'ів
    if (!$isAdmin) {
      if ($threadId !== $safeThread($defaultThread)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
      }
    }

    echo json_encode([
      'ok' => true,
      'thread' => $threadId,
      'is_admin' => $isAdmin,
      'messages' => $data['messages'],
      'meta' => $data['meta'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'send') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      http_response_code(405);
      echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    // For regular user, do not allow sending to чужих thread'ів
    if (!$isAdmin) {
      if ($threadId !== $safeThread($defaultThread)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
      }
    }

    $payload = [];
    $rawBody = file_get_contents('php://input');
    if (is_string($rawBody) && $rawBody !== '') {
      $decoded = json_decode($rawBody, true);
      if (is_array($decoded)) {
        $payload = $decoded;
      }
    }
    if (empty($payload)) {
      $payload = $_POST;
    }

    $text = trim((string)($payload['text'] ?? ''));
    if ($text === '') {
      http_response_code(400);
      echo json_encode(['ok' => false, 'error' => 'Empty message'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    if (mb_strlen($text, 'UTF-8') > 2000) {
      $text = mb_substr($text, 0, 2000, 'UTF-8');
    }

    $data = $readThread($threadFile);

    if (!isset($data['meta']['created_at'])) $data['meta']['created_at'] = $now;
    $data['meta']['updated_at'] = $now;

    // Identify sender
    $senderRole = $isAdmin ? 'admin' : 'user';
    $senderName = $isAdmin ? (string)($_SESSION['user_name'] ?? 'Адмін') : (string)($_SESSION['user_name'] ?? 'Користувач');
    $senderEmail = $isAdmin ? (string)($_SESSION['user_email'] ?? '') : (string)($_SESSION['user_email'] ?? '');

    $msg = [
      'id' => bin2hex(random_bytes(8)),
      'ts' => $now,
      'role' => $senderRole,
      'name' => $senderName,
      'email' => $senderEmail,
      'text' => $text,
    ];

    $data['messages'][] = $msg;

    // Hard cap to avoid huge files
    if (count($data['messages']) > 300) {
      $data['messages'] = array_slice($data['messages'], -300);
    }

    $ok = $writeThread($threadFile, $data);
    if (!$ok) {
      http_response_code(500);
      echo json_encode(['ok' => false, 'error' => 'Failed to save'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    echo json_encode(['ok' => true, 'message' => $msg, 'thread' => $threadId], JSON_UNESCAPED_UNICODE);
    exit;
  }

  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Unknown action'], JSON_UNESCAPED_UNICODE);
  exit;
}

/**
 * ===========================
 * PAGE (normal rendering)
 * ===========================
 */

$isAuthed = !empty($_SESSION['user_id']);
$userNameRaw = (string)($_SESSION['user_name'] ?? '');
$userEmail = (string)($_SESSION['user_email'] ?? '');

$userFirstName = trim($userNameRaw);
if ($userFirstName !== '') {
  $parts = preg_split('/\s+/u', $userFirstName);
  $userFirstName = $parts && isset($parts[0]) ? $parts[0] : $userFirstName;
} else {
  $userFirstName = 'Акаунт';
}

$isAdminUi = !empty($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>ProstoPDR — тести ПДР України</title>
  <meta name="description" content="Тести ПДР України з поясненнями, режимом іспиту, повторенням помилок та статистикою прогресу." />

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Unbounded:wght@500;700&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="/assets/css/style.css?v=4" />

  <!-- Chat styles (inline to avoid editing style.css) -->
  <style>
    .float-chat {
      position: fixed;
      right: 18px;
      bottom: 18px;
      z-index: 9999;
      width: 56px;
      height: 56px;
      border-radius: 999px;
      border: 0;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: #1FA34A;
      color: #fff;
      box-shadow: 0 16px 40px rgba(0,0,0,.22);
    }
    .float-chat__ring {
      position: absolute;
      inset: -6px;
      border-radius: 999px;
      border: 2px solid rgba(31,163,74,.35);
      animation: chatPulse 1.8s infinite;
    }
    @keyframes chatPulse {
      0% { transform: scale(.95); opacity: .65; }
      70% { transform: scale(1.15); opacity: 0; }
      100% { transform: scale(1.15); opacity: 0; }
    }
    .float-chat__icon { position: relative; font-size: 22px; line-height: 1; }

    .chatbox {
      position: fixed;
      right: 18px;
      bottom: 86px;
      width: min(380px, calc(100vw - 36px));
      height: 520px;
      max-height: calc(100vh - 140px);
      z-index: 9999;
      border-radius: 18px;
      overflow: hidden;
      background: #0F1411;
      box-shadow: 0 22px 70px rgba(0,0,0,.35);
      display: none;
    }
    .chatbox.is-open { display: flex; flex-direction: column; }

    .chatbox__head {
      padding: 14px 14px 12px;
      background: rgba(255,255,255,.06);
      border-bottom: 1px solid rgba(255,255,255,.10);
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .chatbox__title { color: #fff; font-weight: 800; font-family: Unbounded, Manrope, system-ui; font-size: 14px; }
    .chatbox__sub { color: rgba(255,255,255,.72); font-size: 12px; margin-top: 2px; }
    .chatbox__head-left { display: flex; flex-direction: column; line-height: 1.15; }
    .chatbox__close {
      margin-left: auto;
      width: 34px;
      height: 34px;
      border-radius: 10px;
      border: 1px solid rgba(255,255,255,.14);
      background: rgba(255,255,255,.06);
      color: #fff;
      cursor: pointer;
    }

    .chatbox__body {
      flex: 1;
      padding: 12px;
      overflow: auto;
      background: radial-gradient(1200px 600px at 80% -10%, rgba(31,163,74,.20), transparent 60%),
                  radial-gradient(1200px 600px at 10% 120%, rgba(31,163,74,.12), transparent 55%),
                  #0F1411;
    }

    .chatmsg {
      display: flex;
      margin: 10px 0;
      gap: 10px;
      align-items: flex-end;
    }
    .chatmsg--user { justify-content: flex-end; }
    .chatmsg--admin { justify-content: flex-start; }

    .chatmsg__bubble {
      max-width: 82%;
      padding: 10px 12px;
      border-radius: 14px;
      font-size: 14px;
      line-height: 1.3;
      white-space: pre-wrap;
      word-wrap: break-word;
      border: 1px solid rgba(255,255,255,.10);
    }
    .chatmsg--user .chatmsg__bubble {
      background: rgba(31,163,74,.18);
      color: #fff;
      border-color: rgba(31,163,74,.30);
      border-bottom-right-radius: 6px;
    }
    .chatmsg--admin .chatmsg__bubble {
      background: rgba(255,255,255,.08);
      color: #fff;
      border-bottom-left-radius: 6px;
    }
    .chatmsg__meta {
      font-size: 11px;
      color: rgba(255,255,255,.55);
      margin-top: 6px;
    }

    .chatbox__foot {
      padding: 10px;
      border-top: 1px solid rgba(255,255,255,.10);
      background: rgba(255,255,255,.06);
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 10px;
      align-items: center;
    }
    .chatbox__input {
      width: 100%;
      resize: none;
      height: 42px;
      max-height: 120px;
      padding: 10px 12px;
      border-radius: 12px;
      border: 1px solid rgba(255,255,255,.16);
      background: rgba(0,0,0,.22);
      color: #fff;
      outline: none;
      font-family: Manrope, system-ui;
      font-size: 14px;
    }
    .chatbox__send {
      height: 42px;
      padding: 0 14px;
      border-radius: 12px;
      border: 0;
      background: #1FA34A;
      color: #fff;
      font-weight: 800;
      cursor: pointer;
      white-space: nowrap;
    }

    .chatadmin {
      display: none;
      padding: 10px 12px;
      border-top: 1px solid rgba(255,255,255,.10);
      background: rgba(0,0,0,.18);
    }
    .chatadmin.is-on { display: flex; gap: 10px; align-items: center; }
    .chatadmin__label { color: rgba(255,255,255,.70); font-size: 12px; }
    .chatadmin__select {
      flex: 1;
      height: 36px;
      border-radius: 10px;
      border: 1px solid rgba(255,255,255,.16);
      background: rgba(0,0,0,.22);
      color: #fff;
      outline: none;
      padding: 0 10px;
      font-family: Manrope, system-ui;
      font-size: 13px;
    }
  </style>
</head>

<body>
  <!-- Floating buttons -->
  <!-- ✅ Replaced phone call with online chat -->
  <button class="float-chat" type="button" aria-label="Онлайн чат" data-chat-open>
    <span class="float-chat__ring"></span>
    <span class="float-chat__icon">💬</span>
  </button>

  <button class="float-top" type="button" aria-label="Вгору" data-scroll-top>
    ↑
  </button>

  <!-- Header -->
  <header class="header" data-header>
    <div class="container header__inner">
      <a class="brand" href="#top" aria-label="На головну">
        <img class="brand__logo" src="/assets/img/logo.svg" alt="ProstoPDR" />
      </a>

      <nav class="nav" aria-label="Головне меню">
        <a class="nav__link" href="#structure">структура</a>
        <a class="nav__link" href="#pricing">тарифи</a>
        <a class="nav__link" href="#program">програма</a>
        <a class="nav__link" href="#faq">faq</a>
      </nav>

      <div class="header__actions">
        <?php if (!$isAuthed): ?>
          <a class="btn btn--ghost header__cta-hide-mobile header__trial" href="/login/index.php">Тестовий доступ на 3 дні</a>
          <a class="btn btn--primary header__cta-hide-mobile" href="/login">увійти</a>
        <?php else: ?>
          <button class="userpill" type="button" data-user-menu-btn aria-label="Профіль">
            <span class="userpill__avatar">🎓</span>
            <span class="userpill__meta">
              <span class="userpill__name"><?php echo htmlspecialchars($userFirstName, ENT_QUOTES, 'UTF-8'); ?></span>
              <span class="userpill__email"><?php echo htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8'); ?></span>
            </span>
            <span class="userpill__chev">▾</span>
          </button>

          <div class="usermenu" data-user-menu>
            <div class="usermenu__head">
              <div class="usermenu__avatar">🎓</div>
              <div class="usermenu__text">
                <div class="usermenu__name"><?php echo htmlspecialchars((string)($_SESSION['user_name'] ?? $userFirstName), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="usermenu__email"><?php echo htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8'); ?></div>
              </div>
            </div>

            <a class="usermenu__item" href="/account"><span class="usermenu__icon">👤</span> Кабінет</a>
            <a class="usermenu__item" href="/account?tab=subscriptions"><span class="usermenu__icon">💳</span> Мої підписки</a>
            <a class="usermenu__item" href="#"><span class="usermenu__icon">🔔</span> Сповіщення <span class="usermenu__badge">1</span></a>
            <a class="usermenu__item usermenu__item--danger" href="/logout"><span class="usermenu__icon">↩</span> Вийти</a>
          </div>
        <?php endif; ?>

        <button class="burger" type="button" aria-label="Меню" data-burger>
          <span></span><span></span><span></span>
        </button>
      </div>
    </div>

    <!-- Mobile menu -->
    <div class="mobile" data-mobile>
      <div class="mobile__top">
        <div class="mobile__title">Меню</div>
        <button class="mobile__close" type="button" aria-label="Закрити" data-mobile-close>✕</button>
      </div>

      <div class="mobile__inner">
        <a class="mobile__link" href="#structure">Структура</a>
        <a class="mobile__link" href="#pricing">Тарифи</a>
        <a class="mobile__link" href="#program">Програма</a>
        <a class="mobile__link" href="#faq">FAQ</a>

        <div class="mobile__divider"></div>

        <?php if ($isAuthed): ?>
          <a class="btn btn--ghost mobile__btn" href="/account">Кабінет</a>
          <a class="btn btn--primary mobile__btn" href="/logout">Вийти</a>
        <?php else: ?>
          <a class="btn btn--ghost mobile__btn" href="/login/index.php">Вхід</a>
          <a class="btn btn--primary mobile__btn" href="/login/index.php">Реєстрація</a>
          <a class="btn btn--ghost mobile__btn" href="#demo">Тестовий доступ 3 дні</a>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <main id="top">
    <!-- Hero -->
    <section class="hero">
      <div class="container hero__grid">
        <div class="hero__content">
          <div class="hero__kicker">
            <span class="hero__arrow"></span>
            
          </div>

          <h1 class="hero__title">
            Тести ПДР України<br />
            з поясненнями<br />
            та статистикою
          </h1>

          <p class="hero__subtitle">
            Вчи правила, тренуйся в режимі «іспит», отримуй пояснення до питань і слідкуй за прогресом щодня.
          </p>

          <div class="hero__cta">
            <a class="btn btn--xl btn--primary" href="#pricing">Почати підготовку</a>
            <a class="btn btn--xl btn--ghost" href="#structure">Дивитись структуру</a>
          </div>
        </div>

        <div class="hero__visual" aria-hidden="true">
          <div class="hero__blob hero__blob--photo">
            <img src="/assets/img/hero-blob.png" alt="" />
          </div>
        </div>
      </div>

      <div class="ribbon">
        <div class="ribbon__track">
          <span>тестовий доступ 3 дні • режим іспиту • пояснення • статистика • повтор помилок</span>
          <span>тестовий доступ 3 дні • режим іспиту • пояснення • статистика • повтор помилок</span>
          <span>тестовий доступ 3 дні • режим іспиту • пояснення • статистика • повтор помилок</span>
          <span>тестовий доступ 3 дні • режим іспиту • пояснення • статистика • повтор помилок</span>
        </div>
      </div>
    </section>

    <!-- Structure -->
    <section class="section" id="structure">
      <div class="container">
        <h2 class="h2">Як працює тренажер тестів ПДР</h2>
        <p class="lead">
          Платформа допомагає підготуватися до теоретичного іспиту: тренування по темах, режим «іспит»,
          пояснення до відповідей, статистика прогресу та повторення помилок.
        </p>

        <div class="structure">
          <div class="structure__photo">
            <img src="/assets/img/structure-photo.jpg" alt="Навчання з інструктором / ПДР" />
            <p class="structure__note">
              Всі матеріали та питання подаються у форматі, наближеному до реального іспиту: таймер, випадкові питання, фіксація помилок.
            </p>
          </div>

          <div class="structure__card">
            <ol class="list-steps">
              <li class="list-steps__item">
                <span class="list-steps__num">1</span>
                <span class="list-steps__text">Тести ПДР з поясненнями до правильної відповіді</span>
              </li>
              <li class="list-steps__item">
                <span class="list-steps__num">2</span>
                <span class="list-steps__text">Режим «іспит»: таймер, випадкові питання, ліміт помилок</span>
              </li>
              <li class="list-steps__item">
                <span class="list-steps__num">3</span>
                <span class="list-steps__text">Повторення помилок і «слабких тем» (підтягуємо те, що не виходить)</span>
              </li>
              <li class="list-steps__item">
                <span class="list-steps__num">4</span>
                <span class="list-steps__text">Статистика прогресу: що вивчено, що треба повторити, динаміка по днях</span>
              </li>
            </ol>
          </div>
        </div>
      </div>
    </section>

    <!-- Program / Pricing -->
    <section class="section section--soft" id="program">
      <div class="container">
        <h2 class="h2">Чому варто готуватись з ProstoPDR</h2>

        <div class="stats">
          <article class="stat stat--type-a">
            <div class="stat__big">1000+</div>
            <div class="stat__text">питань у тренажері з поясненнями та підказками по темах</div>
            <img class="stat__img" src="/assets/img/stat-1.png" alt="" aria-hidden="true" />
          </article>

          <article class="stat stat--type-b">
            <div class="stat__big">Іспит</div>
            <div class="stat__text">режим максимально наближений до реального: таймер, випадкові питання, ліміт помилок</div>
            <img class="stat__img" src="/assets/img/stat-2.png" alt="" aria-hidden="true" />
          </article>

          <article class="stat stat--type-c">
            <div class="stat__big">Прогрес</div>
            <div class="stat__text">щоденна статистика + повторення помилок: вчишся швидше й без хаосу</div>
            <img class="stat__img" src="/assets/img/stat-3.png" alt="" aria-hidden="true" />
          </article>
        </div>

        <div class="section__spacer"></div>

        <div class="choose">
          <div class="choose__hint">
            <div class="choose__title">Обрати тариф</div>
            <div class="choose__arrow" aria-hidden="true">
              <img class="choose__arrow-img" src="/assets/img/choose-arrow.png" alt="" />
            </div>
          </div>

          <div class="pricing" id="pricing">
            <article class="plan plan--basic">
              <h3 class="plan__title">Базовий план<br/>підписка</h3>
              <p class="plan__desc">
                Доступ до тестів ПДР, режиму «іспит», пояснень та статистики. Підписку можна скасувати у будь-який момент.
              </p>

              <div class="plan__price">
                <span class="plan__amount">699,00 грн</span><span class="plan__period">/міс</span>
              </div>

              <div class="plan__banner">
                <span class="dot dot--ok">✓</span>
                Підписка поновлюється автоматично та діє до кінця оплаченого періоду. Доступ одразу після оплати.
              </div>

              <ul class="plan__list">
                <li>Тести ПДР з поясненнями</li>
                <li>Режим «іспит» з таймером</li>
                <li>Повторення помилок та «слабкі теми»</li>
                <li>Статистика прогресу по днях</li>
                <li>Доступ з телефону/ПК у будь-який час</li>
                <li>Нотатки до питань та тем</li>
              </ul>

              <div class="plan__cta-row">
                <a class="btn btn--ghost plan__cta" href="/login/index.php">Отримати 3 дні безкоштовно</a>
                <a class="btn btn--primary plan__cta" href="/login/index.php">Обрати</a>
              </div>
            </article>

            <!-- ✅ План на 12 днів: тепер 2 кнопки як у 699 -->
            <article class="plan plan--personal">
              <h3 class="plan__title">План на 12 днів</h3>
              <p class="plan__desc">
                Доступ до тестів ПДР, режиму «іспит», пояснень та статистики. Підписку можна скасувати у будь-який момент.
              </p>

              <div class="plan__price">
                <span class="plan__amount">389,99 грн</span><span class="plan__period">/12 днів</span>
              </div>

              <div class="plan__banner">
                <span class="dot dot--ok">✓</span>
                Доступ діє 12 днів з моменту оплати. Активується одразу після оплати.
              </div>

              <ul class="plan__list">
                <li>Тести ПДР з поясненнями</li>
                <li>Режим «іспит» з таймером</li>
                <li>Повторення помилок та «слабкі теми»</li>
                <li>Статистика прогресу по днях</li>
                <li>Доступ з телефону/ПК у будь-який час</li>
                <li>Нотатки до питань та тем</li>
              </ul>

              <div class="plan__cta-row">
                <a class="btn btn--ghost plan__cta" href="/login/index.php">Отримати 3 дні безкоштовно</a>
                <a class="btn btn--primary plan__cta" href="/login/index.php">Обрати</a>
              </div>
            </article>
          </div>
        </div>

        <div class="help">
          <div class="help__content">
            <h3 class="h3">Потрібна допомога з підготовкою до ПДР?</h3>
            <p class="help__text">
              Можемо підключити формат з викладачем: короткі пояснення, розбір типових помилок, практика по темах та контроль прогресу.
            </p>
            <a class="btn btn--primary btn--xl" href="/tutor">Детальніше про навчання з викладачем</a>
          </div>

          <div class="help__img" aria-hidden="true">
            <img src="/assets/img/help-3d.png" alt="" />
          </div>
        </div>
      </div>
    </section>

    <!-- Steps -->
    <section class="section">
      <div class="container">
        <h2 class="h2">Процес підготовки в ProstoPDR</h2>

        <div class="big-steps">
          <article class="big-step">
            <div class="big-step__left">
              <h3 class="big-step__title">Крок 1: Реєструєшся на платформі через пошту.</h3>
              <div class="big-step__badge">1</div>
            </div>
            <div class="big-step__right">
              <img class="big-step__img" src="/assets/img/step-1.png" alt="Реєстрація" />
            </div>
          </article>

          <article class="big-step">
            <div class="big-step__left">
              <h3 class="big-step__title">Крок 2: Обираєш тариф: базовий або план на 12 днів.</h3>
              <div class="big-step__badge">2</div>
            </div>
            <div class="big-step__right">
              <div class="mini-cards">
                <div class="mini-card">
                  <div class="mini-card__title">Базовий план</div>
                  <div class="mini-card__text">Тести + іспит + пояснення</div>
                </div>
                <div class="mini-card">
                  <div class="mini-card__title">План на 12 днів</div>
                  <div class="mini-card__text">Повний доступ на 12 днів</div>
                </div>
              </div>
            </div>
          </article>

          <article class="big-step">
            <div class="big-step__left">
              <h3 class="big-step__title">Крок 3: Тренуєшся по темах або в режимі «іспит» — бачиш пояснення та фіксуєш помилки.</h3>
              <div class="big-step__badge">3</div>
            </div>
            <div class="big-step__right">
              <img class="big-step__img" src="/assets/img/step-3.png" alt="Тести та пояснення" />
            </div>
          </article>

          <article class="big-step">
            <div class="big-step__left">
              <h3 class="big-step__title">Крок 4: Повторюєш помилки — система автоматично підкидає «слабкі» питання.</h3>
              <div class="big-step__badge">4</div>
            </div>
            <div class="big-step__right">
              <img class="big-step__img" src="/assets/img/step-4.png" alt="Повтор помилок" />
            </div>
          </article>

          <article class="big-step">
            <div class="big-step__left">
              <h3 class="big-step__title">Крок 5: Відстежуєш статистику: прогрес по питаннях, темах, які треба підтягнути, швидкість і точність.</h3>
              <div class="big-step__badge">5</div>
            </div>
            <div class="big-step__right">
              <img class="big-step__img" src="/assets/img/step-5.png" alt="Статистика" />
            </div>
          </article>

          <article class="big-step">
            <div class="big-step__left">
              <h3 class="big-step__title">Крок 6: Обираєш формат: «змішаний» або «покроково по темах».</h3>
              <div class="big-step__badge">6</div>
            </div>
            <div class="big-step__right">
              <div class="format-cards">
                <div class="format-card">
                  <div class="format-card__title">Все і одразу</div>
                  <div class="format-card__dots">Тренажер та іспити на змішані теми</div>
                </div>
                <div class="format-card format-card--dark">
                  <div class="format-card__title">Покроково</div>
                  <ul class="format-card__list">
                    <li>теми послідовно</li>
                    <li>контроль помилок</li>
                    <li>логічний маршрут</li>
                  </ul>
                </div>
              </div>
            </div>
          </article>

          <article class="big-step">
            <div class="big-step__left">
              <h3 class="big-step__title">Крок 7: Керуєш підпискою — продовжуєш або скасовуєш у будь-який момент.</h3>
              <div class="big-step__badge">7</div>
            </div>
            <div class="big-step__right">
              <img class="big-step__img" src="/assets/img/step-7.png" alt="Підписка" />
            </div>
          </article>
        </div>
      </div>
    </section>

    <!-- Demo -->
    <section class="section section--demo" id="demo">
      <div class="container demo">
        <div class="demo__box">
          <div class="demo__icon">🚗</div>
          <p class="demo__text">
            Хочеш спробувати платформу перед оплатою? Активуй <b>тестовий доступ на 3 дні</b> і пройди тренування безкоштовно.
          </p>
          <a class="btn btn--xl btn--primary" href="/login/index.php">Тестовий доступ на 3 дні</a>
        </div>

        <h2 class="h2">Хочеш готуватися як на реальному іспиті?</h2>
        <div class="exam-date">
          <div class="exam-date__left">
            <div class="exam-date__kicker">→ Режим «іспит» на платформі: таймер, випадкові питання, ліміт помилок.</div>
            <div class="exam-date__big">
              <div>План на 7 днів:</div>
              <div class="exam-date__value">30–60 хв щодня + повторення помилок</div>
            </div>
          </div>
          <a class="btn btn--xl btn--accent" href="/login/index.php">Почати</a>
        </div>
      </div>
    </section>

    <!-- FAQ -->
    <section class="section" id="faq">
      <div class="container">
        <h2 class="h2">FAQ</h2>

        <div class="faq" data-faq>
          <button class="faq__item" type="button" data-faq-item>
            <span>Чи відповідають питання формату теоретичного іспиту?</span>
            <span class="faq__arrow">→</span>
          </button>
          <div class="faq__panel">
            Так. Тренування побудовані так, щоб максимально наблизити досвід до реального іспиту: випадкові питання, таймер, контроль помилок та повторення.
          </div>

          <button class="faq__item" type="button" data-faq-item>
            <span>Що входить у тестовий доступ на 3 дні?</span>
            <span class="faq__arrow">→</span>
          </button>
          <div class="faq__panel">
            Повний доступ до тренажера: тести з поясненнями, режим «іспит», повторення помилок, статистика прогресу та нотатки.
          </div>

          <button class="faq__item" type="button" data-faq-item>
            <span>Чим відрізняється базовий план від плану на 12 днів?</span>
            <span class="faq__arrow">→</span>
          </button>
          <div class="faq__panel">
            Функціонал однаковий (тести, іспит, пояснення, статистика, повтор помилок). Різниця тільки у тривалості доступу та вартості: базовий — на місяць, інший тариф — на 12 днів.
          </div>

          <button class="faq__item" type="button" data-faq-item>
            <span>Як працює повторення помилок?</span>
            <span class="faq__arrow">→</span>
          </button>
          <div class="faq__panel">
            Після тренувань система зберігає питання, де були помилки, і підкидає їх повторно з потрібною частотою, щоб ти закріпив матеріал.
          </div>

          <button class="faq__item" type="button" data-faq-item>
            <span>Чи можна займатись з телефону?</span>
            <span class="faq__arrow">→</span>
          </button>
          <div class="faq__panel">
            Так. Платформа адаптована під мобільні пристрої — можна тренуватись будь-де.
          </div>

          <button class="faq__item" type="button" data-faq-item>
            <span>Як скасувати підписку?</span>
            <span class="faq__arrow">→</span>
          </button>
          <div class="faq__panel">
            Підписку можна скасувати у будь-який момент — вона залишиться активною до кінця оплаченого періоду.
          </div>
        </div>
      </div>
    </section>

  <!-- Footer -->
<footer class="footer">
  <div class="container footer__inner">

    <a class="footer__link" href="/terms.php">
      Правила користування
    </a>

    <div class="footer__copy">
      © ProstoPDR 2019 — <?php echo date('Y'); ?>
      &nbsp;•&nbsp;
      Created by
      <a href="https://mazko.com.ua"
         target="_blank"
         rel="noopener"
         style="color:#1FA34A; font-weight:800; text-decoration:none;">
        MazKo
      </a>
    </div>

    <div class="footer__pay">
      <img src="/assets/img/payments.png" alt="Mastercard Visa">
    </div>

  </div>
</footer>

  <!-- ✅ Chat UI -->
  <div class="chatbox" data-chatbox aria-hidden="true">
    <div class="chatbox__head">
      <div class="chatbox__head-left">
        <div class="chatbox__title">Онлайн чат підтримки</div>
        <div class="chatbox__sub">
          <?php if ($isAdminUi): ?>
            Режим адміністратора — відповідаєш користувачам
          <?php else: ?>
            Напиши нам — адмін відповість у цьому чаті
          <?php endif; ?>
        </div>
      </div>
      <button class="chatbox__close" type="button" aria-label="Закрити" data-chat-close>✕</button>
    </div>

    <?php if ($isAdminUi): ?>
      <div class="chatadmin is-on" data-chatadmin>
        <div class="chatadmin__label">Діалог:</div>
        <select class="chatadmin__select" data-chat-thread-select>
          <option value="">Завантаження…</option>
        </select>
      </div>
    <?php endif; ?>

    <div class="chatbox__body" data-chat-messages></div>

    <div class="chatbox__foot">
      <textarea class="chatbox__input" data-chat-input placeholder="Напиши повідомлення…"></textarea>
      <button class="chatbox__send" type="button" data-chat-send>Надіслати</button>
    </div>
  </div>

  <script src="/assets/js/main.js?v=4"></script>

  <!-- Chat logic (inline to avoid editing main.js) -->
  <script>
    (function () {
      const openBtn = document.querySelector('[data-chat-open]');
      const chatbox = document.querySelector('[data-chatbox]');
      const closeBtn = document.querySelector('[data-chat-close]');
      const listEl = document.querySelector('[data-chat-messages]');
      const inputEl = document.querySelector('[data-chat-input]');
      const sendBtn = document.querySelector('[data-chat-send]');

      const isAdmin = <?php echo $isAdminUi ? 'true' : 'false'; ?>;
      const threadSelect = document.querySelector('[data-chat-thread-select]');

      let currentThread = null;
      let lastRenderedCount = 0;
      let pollTimer = null;

      function esc(s) {
        return String(s || '')
          .replaceAll('&', '&amp;')
          .replaceAll('<', '&lt;')
          .replaceAll('>', '&gt;')
          .replaceAll('"', '&quot;')
          .replaceAll("'", '&#039;');
      }

      function fmtTime(ts) {
        try {
          const d = new Date(ts * 1000);
          return d.toLocaleString('uk-UA', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit' });
        } catch (e) {
          return '';
        }
      }

      function setOpen(state) {
        if (!chatbox) return;
        if (state) {
          chatbox.classList.add('is-open');
          chatbox.setAttribute('aria-hidden', 'false');
          if (!pollTimer) {
            pollTimer = setInterval(fetchMessages, 2500);
          }
          fetchInit();
          setTimeout(() => { inputEl && inputEl.focus(); }, 50);
        } else {
          chatbox.classList.remove('is-open');
          chatbox.setAttribute('aria-hidden', 'true');
          if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
          }
        }
      }

      async function api(url, options) {
        const res = await fetch(url, Object.assign({
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' }
        }, options || {}));
        return res.json();
      }

      async function fetchInit() {
        if (isAdmin) {
          await loadThreads();
          if (!currentThread && threadSelect && threadSelect.value) {
            currentThread = threadSelect.value;
          }
        }
        await fetchMessages();
      }

      async function loadThreads() {
        if (!threadSelect) return;
        const data = await api('?chat_api=1&action=list', { method: 'GET' });
        if (!data || !data.ok) {
          threadSelect.innerHTML = '<option value="">Не вдалося завантажити</option>';
          return;
        }
        const threads = Array.isArray(data.threads) ? data.threads : [];
        if (!threads.length) {
          threadSelect.innerHTML = '<option value="">Немає діалогів</option>';
          currentThread = null;
          lastRenderedCount = 0;
          listEl.innerHTML = '';
          return;
        }
        const opts = threads.map(t => {
          const label = t.thread + ' • ' + (t.updated_at ? fmtTime(t.updated_at) : '');
          const sel = (currentThread && currentThread === t.thread) ? ' selected' : '';
          return `<option value="${esc(t.thread)}"${sel}>${esc(label)}</option>`;
        }).join('');
        threadSelect.innerHTML = opts;

        if (!currentThread) {
          currentThread = threads[0].thread;
        }
      }

      async function fetchMessages() {
        if (!listEl) return;

        const qs = isAdmin && currentThread ? ('&thread=' + encodeURIComponent(currentThread)) : '';
        const data = await api('?chat_api=1&action=fetch' + qs, { method: 'GET' });

        if (!data || !data.ok) return;

        const msgs = Array.isArray(data.messages) ? data.messages : [];

        // rerender only if changed
        if (msgs.length === lastRenderedCount) return;
        lastRenderedCount = msgs.length;

        const html = msgs.map(m => {
          const role = (m.role === 'admin') ? 'admin' : 'user';
          const cls = role === 'admin' ? 'chatmsg chatmsg--admin' : 'chatmsg chatmsg--user';
          const meta = `${esc(m.name || (role === 'admin' ? 'Адмін' : 'Користувач'))} • ${fmtTime(m.ts || 0)}`;
          return `
            <div class="${cls}">
              <div class="chatmsg__bubble">
                ${esc(m.text || '')}
                <div class="chatmsg__meta">${meta}</div>
              </div>
            </div>
          `;
        }).join('');

        listEl.innerHTML = html;

        // scroll to bottom
        listEl.scrollTop = listEl.scrollHeight;
      }

      async function sendMessage() {
        if (!inputEl) return;
        const text = inputEl.value.trim();
        if (!text) return;

        inputEl.value = '';
        inputEl.style.height = '';
        const qs = isAdmin && currentThread ? ('&thread=' + encodeURIComponent(currentThread)) : '';
        const data = await api('?chat_api=1&action=send' + qs, {
          method: 'POST',
          body: JSON.stringify({ text })
        });
        if (!data || !data.ok) return;
        await fetchMessages();
      }

      // Auto-resize textarea
      function autoResize() {
        if (!inputEl) return;
        inputEl.style.height = 'auto';
        inputEl.style.height = Math.min(inputEl.scrollHeight, 120) + 'px';
      }

      if (openBtn) openBtn.addEventListener('click', () => setOpen(true));
      if (closeBtn) closeBtn.addEventListener('click', () => setOpen(false));

      if (sendBtn) sendBtn.addEventListener('click', sendMessage);

      if (inputEl) {
        inputEl.addEventListener('input', autoResize);
        inputEl.addEventListener('keydown', (e) => {
          if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
          }
        });
      }

      if (threadSelect) {
        threadSelect.addEventListener('change', async () => {
          currentThread = threadSelect.value || null;
          lastRenderedCount = 0;
          listEl.innerHTML = '';
          await fetchMessages();
        });
      }

      // Close on ESC
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') setOpen(false);
      });
    })();
  </script>

  <?php require_once __DIR__ . '/partials/chat_widget.php'; ?>
</body>
</html>