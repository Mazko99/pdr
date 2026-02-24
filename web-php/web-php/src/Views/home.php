<?php
declare(strict_types=1);

ob_start();
require __DIR__ . '/partials/header.php';
?>

<main>
  <!-- HERO -->
  <section class="hero">
    <div class="container hero__inner">
      <div class="hero__content">
        <div class="pill">Курс для студентів-правників (магістерський рівень)</div>
        <h1>Підготовка до Єдиного державного кваліфікаційного іспиту (ЄДКІ)</h1>
        <p class="lead">
          Онлайн-платформа для підготовки: тести, теорія, відео, прогрес і персональні плани.
        </p>
        <div class="hero__cta">
          <a class="btn btn--primary" href="#plans">Готуватись</a>
          <a class="btn btn--secondary" href="#demo">Демо-доступ</a>
        </div>

        <div class="ticker" aria-label="Оголошення">
          <div class="ticker__track">
            <span>Дата ЄДКІ — встав тут свою дату •</span>
            <span>Доступ з будь-якого пристрою •</span>
            <span>Тести у форматі іспиту •</span>
          </div>
        </div>
      </div>

      <div class="hero__card">
        <div class="card">
          <div class="card__title">Швидкий старт</div>
          <ul class="checklist">
            <li>Реєстрація через email/Google</li>
            <li>Обираєш базовий або персональний план</li>
            <li>Оплата та доступ одразу</li>
          </ul>
          <div class="card__hint muted">* Текст/цифри заміни під свій проєкт.</div>
        </div>
      </div>
    </div>
  </section>

  <!-- ABOUT / STRUCTURE -->
  <section class="section" id="about">
    <div class="container">
      <h2>Що таке ЄДКІ? Структура іспиту</h2>
      <p class="muted">
        Опис блоку: фабули/питання, час, типи завдань — це заповнюваний контент (з адмінки пізніше).
      </p>

      <div class="grid grid--4">
        <div class="stat">
          <div class="stat__num">120</div>
          <div class="stat__label">Завдань (приклад)</div>
        </div>
        <div class="stat">
          <div class="stat__num">180</div>
          <div class="stat__label">Хвилин (приклад)</div>
        </div>
        <div class="stat">
          <div class="stat__num">1–4</div>
          <div class="stat__label">Типи завдань</div>
        </div>
        <div class="stat">
          <div class="stat__num">∞</div>
          <div class="stat__label">Тренувань</div>
        </div>
      </div>
    </div>
  </section>

  <!-- WHY -->
  <section class="section section--soft">
    <div class="container">
      <h2>Чому потрібно готуватись</h2>
      <div class="grid grid--3">
        <div class="info">
          <div class="info__badge">12%</div>
          <div class="info__text">Приклад статистики — заміни реальними даними.</div>
        </div>
        <div class="info">
          <div class="info__badge">Поріг</div>
          <div class="info__text">Пояснення про пороговий бал (контент керований).</div>
        </div>
        <div class="info">
          <div class="info__badge">49%</div>
          <div class="info__text">Ще одна метрика/ризик (контент керований).</div>
        </div>
      </div>
    </div>
  </section>

  <!-- PLANS -->
  <section class="section" id="plans">
    <div class="container">
      <h2>Обрати курс</h2>

      <div class="grid grid--2">
        <div class="plan">
          <div class="plan__head">
            <div class="plan__title">Базовий план (підписка)</div>
            <div class="plan__price">1000 грн/міс</div>
          </div>
          <ul class="bullets">
            <li>Тести у форматі ЄДКІ</li>
            <li>Відео-матеріали</li>
            <li>Відстеження прогресу</li>
            <li>Нотатки до матеріалів</li>
          </ul>
          <a class="btn btn--primary btn--full" href="#demo">Обрати</a>
          <div class="muted small">Підписка поновлюється автоматично (логіка — пізніше в кабінеті).</div>
        </div>

        <div class="plan plan--accent">
          <div class="plan__head">
            <div class="plan__title">Персональний план</div>
            <div class="plan__price">Індивідуально</div>
          </div>
          <ol class="steps">
            <li>Короткий тест</li>
            <li>Опитування</li>
            <li>План навчання під тебе</li>
          </ol>
          <a class="btn btn--secondary btn--full" href="#process">Обрати</a>
          <div class="muted small">Персоналізація — через API (пізніше).</div>
        </div>
      </div>
    </div>
  </section>

  <!-- PROCESS -->
  <section class="section section--soft" id="process">
    <div class="container">
      <h2>Процес навчання</h2>

      <div class="timeline">
        <div class="tl">
          <div class="tl__num">1</div><div class="tl__text">Реєстрація (email/Google)</div>
        </div>
        <div class="tl">
          <div class="tl__num">2</div><div class="tl__text">Вибір формату підготовки</div>
        </div>
        <div class="tl">
          <div class="tl__num">3</div><div class="tl__text">Тест для персонального плану</div>
        </div>
        <div class="tl">
          <div class="tl__num">4</div><div class="tl__text">Оплата/оплата частинами (пізніше)</div>
        </div>
        <div class="tl">
          <div class="tl__num">5</div><div class="tl__text">Тести, теорія, мультимедіа</div>
        </div>
        <div class="tl">
          <div class="tl__num">6</div><div class="tl__text">Прогрес, рейтинги, режим навчання</div>
        </div>
        <div class="tl">
          <div class="tl__num">7</div><div class="tl__text">Керування підпискою</div>
        </div>
      </div>

      <div class="cta" id="demo">
        <div>
          <div class="cta__title">Спробувати демо</div>
          <div class="muted">Перший урок/демо-тести безкоштовно (реалізуємо через кабінет).</div>
        </div>
        <a class="btn btn--primary" href="/signup">Демо-доступ</a>
      </div>
    </div>
  </section>

  <!-- MATERIALS -->
  <section class="section">
    <div class="container">
      <h2>Які матеріали доступні</h2>
      <div class="grid grid--4">
        <div class="tile">Відеолекції</div>
        <div class="tile">Тести з поясненнями</div>
        <div class="tile">Теорія + нотатки</div>
        <div class="tile">Пробне тестування</div>
      </div>
    </div>
  </section>

  <!-- PROGRAM -->
  <section class="section section--soft" id="program">
    <div class="container">
      <h2>Програма курсу</h2>
      <p class="muted">Список розділів (зараз статично, потім — з БД).</p>

      <div class="accordion">
        <details open>
          <summary>01. Конституційне право України</summary>
          <ul class="bullets">
            <li>Теми/підтеми — заповнюваний контент</li>
          </ul>
        </details>

        <details>
          <summary>02. Основи права ЄС</summary>
          <ul class="bullets">
            <li>Теми/підтеми — заповнюваний контент</li>
          </ul>
        </details>

        <details>
          <summary>03. Міжнародне публічне право</summary>
          <ul class="bullets">
            <li>Теми/підтеми — заповнюваний контент</li>
          </ul>
        </details>

        <details>
          <summary>… Додай решту розділів</summary>
          <div class="muted small">Пізніше це буде з адмінки/БД.</div>
        </details>
      </div>
    </div>
  </section>
</main>

<?php require __DIR__ . '/partials/footer.php'; ?>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
