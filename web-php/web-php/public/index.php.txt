<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
  @session_start();
}

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
</head>

<body>
  <!-- Floating buttons -->
  <a class="float-call" href="tel:+380000000000" aria-label="Зателефонувати">
    <span class="float-call__ring"></span>
    <span class="float-call__icon">📞</span>
  </a>

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
          <a class="btn btn--ghost header__cta-hide-mobile" href="#demo">Тестовий доступ на 3 дні</a>
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
            <a class="usermenu__item" href="#"><span class="usermenu__icon">🧑‍🏫</span> Викладач</a>
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
          <a class="btn btn--ghost mobile__btn" href="/login">Вхід</a>
          <a class="btn btn--primary mobile__btn" href="/register">Реєстрація</a>
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
            <span class="hero__arrow">→</span>
            онлайн-підготовка до теоретичного іспиту та тренажер тестів ПДР
          </div>

          <h1 class="hero__title">
            Тести ПДР України<br />
            з поясненнями<br />
            та статистикою
          </h1>

          <p class="hero__subtitle">
            Вчи правила, тренуйся в режимі «іспит», отримуй пояснення до питань і бач свій прогрес щодня.
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

      <!-- ribbon / announcement -->
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

    <!-- Why prepare / Program -->
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

    <!-- Steps / Process (НЕ ВИРІЗАВ, ЛИШАЄТЬСЯ 7 КРОКІВ) -->
    <section class="section">
      <div class="container">
        <h2 class="h2">Процес підготовки в ProstoPDR</h2>

        <div class="big-steps">
          <article class="big-step">
            <div class="big-step__left">
              <h3 class="big-step__title">Крок 1: Реєструєшся на платформі через пошту або Google.</h3>
              <div class="big-step__badge">1</div>
            </div>
            <div class="big-step__right">
              <img class="big-step__img" src="/assets/img/step-1.png" alt="Реєстрація" />
            </div>
          </article>

          <article class="big-step">
            <div class="big-step__left">
              <h3 class="big-step__title">Крок 2: Обираєш тариф: базовий або персональний план.</h3>
              <div class="big-step__badge">2</div>
            </div>
            <div class="big-step__right">
              <div class="mini-cards">
                <div class="mini-card">
                  <div class="mini-card__title">Базовий план</div>
                  <div class="mini-card__text">Тести + іспит + пояснення</div>
                </div>
                <div class="mini-card">
                  <div class="mini-card__title">Персональний план</div>
                  <div class="mini-card__text">Маршрут по слабких темах</div>
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
              <h3 class="big-step__title">Крок 5: Відстежуєш статистику: прогрес по днях, теми, які треба підтягнути, швидкість і точність.</h3>
              <div class="big-step__badge">5</div>
            </div>
            <div class="big-step__right">
              <img class="big-step__img" src="/assets/img/step-5.png" alt="Статистика" />
            </div>
          </article>

          <article class="big-step">
            <div class="big-step__left">
              <h3 class="big-step__title">Крок 6: Обираєш формат: «все підряд» або «покроково по темах».</h3>
              <div class="big-step__badge">6</div>
            </div>
            <div class="big-step__right">
              <div class="format-cards">
                <div class="format-card">
                  <div class="format-card__title">Все і одразу</div>
                  <div class="format-card__dots">• • •</div>
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
          <a class="btn btn--xl btn--primary" href="/demo">Тестовий доступ на 3 дні</a>
        </div>

        <h2 class="h2">Хочеш готуватися як на реальному іспиті?</h2>
        <div class="exam-date">
          <div class="exam-date__left">
            <div class="exam-date__kicker">→ Режим «іспит» на платформі: таймер, випадкові питання, ліміт помилок.</div>
            <div class="exam-date__big">
              <div>План на 7 днів:</div>
              <div class="exam-date__value">30–60 хв щодня + повтор помилок</div>
            </div>
          </div>
          <a class="btn btn--xl btn--accent" href="#pricing">Почати</a>
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
            <span>Чим відрізняється базовий план від персонального?</span>
            <span class="faq__arrow">→</span>
          </button>
          <div class="faq__panel">
            Базовий — повний функціонал платформи. Персональний — додатково дає адаптивний маршрут по слабких темах та рекомендації, що проходити далі.
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

    <!-- Socials -->
    <section class="section section--social">
      <div class="container social">
        <h2 class="h2">Наші соціальні мережі:</h2>
        <div class="social__links">
          <a class="social__btn" href="#" aria-label="Instagram">
            <img src="/assets/img/socials-instagram.svg" alt="" />
          </a>
          <a class="social__btn" href="#" aria-label="Telegram">
            <img src="/assets/img/socials-telegram.svg" alt="" />
          </a>
        </div>
      </div>
    </section>

  </main>

  <!-- Footer -->
  <footer class="footer">
    <div class="container footer__inner">
      <a class="footer__link" href="/rules">Правила користування</a>
      <div class="footer__copy">© ProstoPDR 2019 — <?php echo date('Y'); ?></div>
      <div class="footer__pay">
        <img src="/assets/img/payments.png" alt="Mastercard Visa" />
      </div>
    </div>
  </footer>

  <script src="/assets/js/main.js?v=4"></script>
</body>
</html>
