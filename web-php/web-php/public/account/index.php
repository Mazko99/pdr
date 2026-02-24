<?php
declare(strict_types=1);

$bootstrap = __DIR__ . '/../../src/bootstrap.php';
$usersStore = __DIR__ . '/../../src/users_store.php';

if (is_file($bootstrap)) require_once $bootstrap;
if (is_file($usersStore)) require_once $usersStore;

if (session_status() !== PHP_SESSION_ACTIVE) {
  @session_start();
}

$uid = $_SESSION['user_id'] ?? null;
if (!$uid) {
  header('Location: /login', true, 302);
  exit;
}

$uidStr = (string)$uid;
$user = function_exists('user_find_by_id') ? user_find_by_id($uidStr) : null;

$nameRaw = (string)($user['name'] ?? ($_SESSION['user_name'] ?? 'Користувач'));
$email = (string)($user['email'] ?? ($_SESSION['user_email'] ?? ''));

$nameFirst = trim($nameRaw);
if ($nameFirst !== '') {
  $parts = preg_split('/\s+/u', $nameFirst);
  $nameFirst = $parts && isset($parts[0]) ? $parts[0] : $nameFirst;
} else {
  $nameFirst = 'Користувач';
}

$tab = (string)($_GET['tab'] ?? 'dashboard');
$allowedTabs = ['dashboard', 'subscriptions'];
if (!in_array($tab, $allowedTabs, true)) $tab = 'dashboard';

// заглушка підписки
$subscription = [
  'plan' => '—',
  'status' => '—',
  'expires_at' => '—',
];
?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Кабінет — ProstoPDR</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Unbounded:wght@500;700&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="/assets/css/style.css?v=4" />
</head>
<body>

<header class="header">
  <div class="container header__inner">
    <a class="brand" href="/" aria-label="На головну">
      <img class="brand__logo" src="/assets/img/logo.svg" alt="ProstoPDR" />
    </a>

    <div class="header__actions">
      <button class="userpill" type="button" data-user-menu-btn aria-label="Профіль">
        <span class="userpill__avatar">🎓</span>
        <span class="userpill__meta">
          <span class="userpill__name"><?php echo htmlspecialchars($nameFirst, ENT_QUOTES, 'UTF-8'); ?></span>
          <span class="userpill__email"><?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?></span>
        </span>
        <span class="userpill__chev">▾</span>
      </button>

      <div class="usermenu" data-user-menu>
        <div class="usermenu__head">
          <div class="usermenu__avatar">🎓</div>
          <div class="usermenu__text">
            <div class="usermenu__name"><?php echo htmlspecialchars($nameRaw, ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="usermenu__email"><?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?></div>
          </div>
        </div>

        <a class="usermenu__item" href="/account"><span class="usermenu__icon">👤</span> Кабінет</a>
        <a class="usermenu__item" href="#"><span class="usermenu__icon">🧑‍</span> Викладач</a>
        <a class="usermenu__item" href="/account?tab=subscriptions"><span class="usermenu__icon">💳</span> Мої підписки</a>
        <a class="usermenu__item" href="/"><span class="usermenu__icon">🏠</span> На головну</a>
        <a class="usermenu__item usermenu__item--danger" href="/logout"><span class="usermenu__icon">↩</span> Вийти</a>
      </div>

      <button class="burger" type="button" aria-label="Меню" data-burger>
        <span></span><span></span><span></span>
      </button>
    </div>
  </div>

  <!-- Mobile menu for account -->
  <div class="mobile" data-mobile>
    <div class="mobile__top">
      <div class="mobile__title">Меню</div>
      <button class="mobile__close" type="button" aria-label="Закрити" data-mobile-close>✕</button>
    </div>

    <div class="mobile__inner">
      <a class="mobile__link" href="/account">Кабінет</a>
      <a class="mobile__link" href="/account?tab=subscriptions">Мої підписки</a>
      <a class="mobile__link" href="/">На головну</a>

      <div class="mobile__divider"></div>

      <a class="btn btn--primary mobile__btn" href="/logout">Вийти</a>
    </div>
  </div>
</header>

<main class="section section--soft" style="padding-top:46px;">
  <div class="container">
    <h2 class="h2">Кабінет</h2>
    <p class="lead">Тут буде прогрес, статистика та повторення помилок. Поки робимо основу.</p>

    <div class="account-tabs">
      <a class="account-tab <?php echo $tab==='dashboard'?'is-active':''; ?>" href="/account?tab=dashboard">Кабінет</a>
      <a class="account-tab <?php echo $tab==='subscriptions'?'is-active':''; ?>" href="/account?tab=subscriptions">Мої підписки</a>
    </div>

    <?php if ($tab === 'dashboard'): ?>

      <!-- 1) СПОЧАТКУ ТАРИФИ (як на фото 5) -->
      <div class="account-block">
        <h3 class="h3">Обрати тариф</h3>

        <div class="pricing pricing--account">
          <article class="plan plan--basic">
            <h3 class="plan__title">Базовий план<br/>підписка</h3>
            <p class="plan__desc">
              Доступ до тестів ПДР, режиму «іспит», пояснень та статистики. Підписку можна скасувати у будь-який момент.
            </p>

            <div class="plan__price">
              <span class="plan__amount">1000,00 грн</span><span class="plan__period">/міс</span>
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
              <a class="btn btn--ghost plan__cta" href="/demo">Отримати 3 дні безкоштовно</a>
              <a class="btn btn--primary plan__cta" href="/checkout?plan=basic">Обрати</a>
            </div>
          </article>

          <article class="plan plan--personal">
            <h3 class="plan__title">Персональний план</h3>
            <p class="plan__desc">
              Індивідуальний маршрут: тренування по твоїх слабких темах, рекомендації й контроль прогресу.
            </p>

            <div class="plan__media">
              <img src="/assets/img/plan-personal.png" alt="" />
            </div>

            <ol class="plan__steps">
              <li>Швидкий старт-тест — визначимо твій рівень</li>
              <li>План підготовки — теми та вправи під тебе</li>
              <li>Повтор помилок — автоматично підкидаємо те, що «просідає»</li>
            </ol>

            <a class="btn btn--accent plan__cta plan__cta--single" href="/checkout?plan=personal">Обрати</a>
          </article>
        </div>
      </div>

      <!-- 2) ТІЛЬКИ ПОТІМ АКТИВНІ ПРЕДМЕТИ + ШВИДКІ ДІЇ -->
      <div class="account-grid" style="margin-top:18px;">
        <div class="account-card">
          <h3 class="h3">Ваші активні предмети</h3>
          <p class="lead" style="margin-top:8px;">Далі тут підключимо твою структуру як на скріні (прогрес, іспити, формат навчання).</p>
        </div>

        <div class="account-card">
          <h3 class="h3">Швидкі дії</h3>
          <div style="display:flex; gap:12px; flex-wrap:wrap; margin-top:12px;">
            <a class="btn btn--primary" href="#">Пробне тестування</a>
            <a class="btn btn--ghost" href="#">Повтор помилок</a>
          </div>
        </div>
      </div>

    <?php else: ?>
      <div class="account-grid">
        <div class="account-card">
          <h3 class="h3">Мої підписки</h3>

          <div class="sub-card">
            <div class="sub-card__row">
              <div class="sub-card__label">План</div>
              <div class="sub-card__value"><?php echo htmlspecialchars($subscription['plan'], ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <div class="sub-card__row">
              <div class="sub-card__label">Статус</div>
              <div class="sub-card__value"><?php echo htmlspecialchars($subscription['status'], ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <div class="sub-card__row">
              <div class="sub-card__label">Діє до</div>
              <div class="sub-card__value"><?php echo htmlspecialchars($subscription['expires_at'], ENT_QUOTES, 'UTF-8'); ?></div>
            </div>

            <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;">
              <a class="btn btn--primary" href="/#pricing">Змінити тариф</a>
              <a class="btn btn--ghost" href="#">Скасувати підписку</a>
            </div>
          </div>
        </div>

        <div class="account-card">
          <h3 class="h3">Оплата</h3>
          <p class="lead">Далі додамо історію платежів та чек.</p>
        </div>
      </div>
    <?php endif; ?>
  </div>
</main>

<script src="/assets/js/main.js?v=4"></script>
</body>
</html>
