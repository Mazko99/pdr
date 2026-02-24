<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/users_store.php';

$uid = auth_user_id();
if (!$uid) redirect('/login');

$user = user_find_by_id($uid);
if (!$user) {
  auth_logout();
  redirect('/login');
}

$name = trim((string)($user['name'] ?? ''));
$email = (string)($user['email'] ?? '');
$plan = $user['plan'] ?? null;

?><!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Кабінет — ProstoPDR</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Unbounded:wght@500;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/style.css?v=2" />
</head>

<body>
  <header class="header" data-header>
    <div class="container header__inner">
      <a class="brand" href="/" aria-label="На головну">
        <img class="brand__logo" src="/assets/img/logo.svg" alt="ProstoPDR" />
      </a>

      <nav class="nav" aria-label="Головне меню">
        <a class="nav__link" href="/">головна</a>
        <a class="nav__link" href="/#pricing">тарифи</a>
        <a class="nav__link" href="/#faq">faq</a>
      </nav>

      <div class="header__actions">
        <button class="userpill" type="button" data-user-menu-btn aria-label="Профіль">
          <span class="userpill__avatar">🎓</span>
          <span class="userpill__meta">
            <span class="userpill__name"><?php echo htmlspecialchars($name !== '' ? $name : 'Користувач', ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="userpill__email"><?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?></span>
          </span>
          <span class="userpill__chev">▾</span>
        </button>

        <div class="usermenu" data-user-menu>
          <div class="usermenu__head">
            <div class="usermenu__avatar">🎓</div>
            <div class="usermenu__text">
              <div class="usermenu__name"><?php echo htmlspecialchars($name !== '' ? $name : 'Користувач', ENT_QUOTES, 'UTF-8'); ?></div>
              <div class="usermenu__email"><?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
          </div>

          <a class="usermenu__item" href="/account">
            <span class="usermenu__icon">👤</span> Кабінет
          </a>
          <a class="usermenu__item" href="/#pricing">
            <span class="usermenu__icon">💳</span> Мої підписки
          </a>
          <a class="usermenu__item" href="#">
            <span class="usermenu__icon">🔔</span> Сповіщення <span class="usermenu__badge">1</span>
          </a>
          <a class="usermenu__item usermenu__item--danger" href="/logout">
            <span class="usermenu__icon">↩</span> Вийти
          </a>
        </div>

        <button class="burger" type="button" aria-label="Меню" data-burger>
          <span></span><span></span><span></span>
        </button>
      </div>
    </div>

    <!-- Mobile full-screen menu -->
    <div class="mobile mobile--fullscreen" data-mobile>
      <div class="mobile__inner mobile__inner--fullscreen">
        <div class="mobile__links">
          <a class="mobile__link" href="/account">Кабінет</a>
          <a class="mobile__link" href="/#pricing">Тарифи</a>
          <a class="mobile__link" href="/#faq">FAQ</a>
          <a class="mobile__link" href="/">На головну</a>
        </div>

        <div class="mobile__cta mobile__cta--fullscreen">
          <a class="btn btn--ghost" href="/logout">Вийти</a>
        </div>
      </div>
    </div>
  </header>

  <main class="section section--soft">
    <div class="container">
      <h1 class="h2">Кабінет</h1>
      <p class="lead">Тут буде прогрес, формати навчання, іспити та підписки.</p>

      <?php if (!$plan): ?>
        <section class="account-plans">
          <h2 class="h3">Обери план</h2>
          <div class="account-plans__grid">
            <article class="plan plan--basic">
              <h3 class="plan__title">Базовий план<br/>підписка</h3>
              <p class="plan__desc">Тести ПДР, режим «іспит», пояснення та статистика.</p>
              <div class="plan__price">
                <span class="plan__amount">1000,00 грн</span><span class="plan__period">/міс</span>
              </div>
              <ul class="plan__list">
                <li>Тести з поясненнями</li>
                <li>Іспит з таймером</li>
                <li>Повтор помилок</li>
                <li>Статистика по днях</li>
              </ul>
              <form method="post" action="/account/plan.php">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="plan" value="basic">
                <button class="btn btn--primary plan__cta plan__cta--single" type="submit">Обрати</button>
              </form>
            </article>

            <article class="plan plan--personal">
              <h3 class="plan__title">Персональний план</h3>
              <p class="plan__desc">Маршрут по слабких темах + рекомендації.</p>

              <div class="plan__media">
                <img src="/assets/img/plan-personal.png" alt="" />
              </div>

              <ol class="plan__steps">
                <li>Старт-тест — визначимо рівень</li>
                <li>План підготовки під тебе</li>
                <li>Авто-повтор “що просідає”</li>
              </ol>

              <form method="post" action="/account/plan.php">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="plan" value="personal">
                <button class="btn btn--accent plan__cta plan__cta--single" type="submit">Обрати</button>
              </form>
            </article>
          </div>
        </section>
      <?php else: ?>
        <div class="notice notice--ok" style="margin-bottom:16px;">
          Активний план: <b><?php echo htmlspecialchars($plan, ENT_QUOTES, 'UTF-8'); ?></b>
        </div>
      <?php endif; ?>

      <section class="account-grid">
        <div class="account-main">
          <div class="account-card">
            <div class="account-card__head">
              <h2 class="h3" style="margin:0;">Ваші активні предмети</h2>
              <div class="account-card__meta">0% пройдено</div>
            </div>

            <div class="subjects">
              <div class="subject subject--active">
                <div class="subject__icon">📘</div>
                <div class="subject__name">Тести ПДР України</div>
                <div class="subject__sub">Старт з теорії</div>
              </div>
              <div class="subject">
                <div class="subject__icon">🧠</div>
                <div class="subject__name">Слабкі теми</div>
                <div class="subject__sub">Авто-повтор</div>
              </div>
              <div class="subject">
                <div class="subject__icon">⏱</div>
                <div class="subject__name">Режим «іспит»</div>
                <div class="subject__sub">Таймер + ліміт помилок</div>
              </div>
              <div class="subject">
                <div class="subject__icon">📊</div>
                <div class="subject__name">Статистика</div>
                <div class="subject__sub">По днях і темах</div>
              </div>
            </div>

            <div class="progressline">
              <div class="progressline__bar" style="width:0%;"></div>
            </div>
          </div>
        </div>

        <aside class="account-side">
          <div class="account-card">
            <div class="side-streak">
              <div class="side-streak__title">0 днів поспіль</div>
              <div class="side-streak__sub">Заверши 1 урок, щоб розпочати стрік</div>
              <div class="side-days">
                <span class="side-day is-off">Пн</span>
                <span class="side-day is-off">Вт</span>
                <span class="side-day is-off">Ср</span>
                <span class="side-day is-off">Чт</span>
                <span class="side-day is-on">Пт</span>
                <span class="side-day is-off">Сб</span>
                <span class="side-day is-off">Нд</span>
              </div>
            </div>
          </div>

          <div class="account-card account-card--dark">
            <div class="side-goal">
              <div class="side-goal__title">Завершіть 400 тестів</div>
              <div class="side-goal__sub">щоб відкрити можливість перемикатись між форматами навчання</div>
              <div class="side-goal__progress">
                <span class="side-goal__pill">0%</span>
                <div class="side-goal__bar"><div style="width:0%"></div></div>
              </div>
            </div>
          </div>

          <div class="account-card">
            <div class="side-block__title">Формат навчання</div>
            <div class="side-toggle">
              <button class="side-toggle__btn is-active" type="button">Покроково</button>
              <button class="side-toggle__btn" type="button">Все і одразу</button>
            </div>
          </div>

          <div class="account-card">
            <div class="side-block__title">Твої екзамени</div>
            <div class="side-actions">
              <a class="side-action" href="#">Пробне тестування</a>
              <a class="side-action" href="#">Пробний тест 1</a>
            </div>
          </div>
        </aside>
      </section>
    </div>
  </main>

  <script src="/assets/js/main.js?v=2"></script>
</body>
</html>
