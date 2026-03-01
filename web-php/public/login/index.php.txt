<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

if (auth_user_id()) {
  redirect('/account');
}

/**
 * ✅ ДОДАНО: красиві повідомлення для:
 * - ?reason=another_device
 * - ?reason=max_devices
 */
$reason = isset($_GET['reason']) ? (string)$_GET['reason'] : '';

$err = isset($_GET['err']) ? (string)$_GET['err'] : '';
$ok  = isset($_GET['ok']) ? (string)$_GET['ok'] : '';

// якщо err/ok не задані вручну — підставляємо reason
if ($err === '' && $ok === '' && $reason !== '') {
  if ($reason === 'another_device') {
    $err = 'Сесію завершено: в акаунт увійшли з іншого пристрою. Якщо це були не ви — змініть пароль.';
  } elseif ($reason === 'max_devices') {
    $err = 'Ліміт пристроїв вичерпано. До одного акаунта можна підключити максимум 2 пристрої.';
  }
}

$csrf = csrf_token();
?><!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Вхід / Реєстрація — ProstoPDR</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Unbounded:wght@500;700&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="/assets/css/style.css?v=2" />

  <style>
    .auth-page{ padding: 48px 0 70px; }
    .auth-wrap{
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
      align-items:stretch;
      margin-top: 18px;
    }
    .auth-card{
      background:#fff;
      border-radius: var(--radius);
      border: 1px solid rgba(11,27,20,.14);
      box-shadow: var(--shadow);
      padding: 22px;
      overflow:hidden;
    }
    .auth-title{
      font-family: var(--font-display);
      font-size: 34px;
      line-height: 1.05;
      margin: 0 0 10px;
    }
    .auth-sub{
      margin: 0 0 16px;
      color: rgba(11,27,20,.70);
      line-height: 1.45;
      font-weight: 650;
    }

    .tabs{ display:flex; gap:10px; flex-wrap:wrap; margin-bottom: 14px; }
    .tab{
      border-radius: 999px;
      padding: 10px 14px;
      border: 1px solid rgba(11,27,20,.12);
      background: #f7fffb;
      font-weight: 900;
      cursor:pointer;
    }
    .tab.is-active{
      background: rgba(14,122,67,.10);
      border-color: rgba(14,122,67,.26);
      color: var(--green-dark);
    }

    .field{ display:flex; flex-direction:column; gap:8px; margin-bottom: 12px; }
    .label{ font-weight: 800; color: rgba(11,27,20,.78); }
    .input{
      border-radius: 16px;
      border: 1px solid rgba(11,27,20,.14);
      padding: 12px 14px;
      font-weight: 650;
      outline: none;
      background:#fff;
    }
    .input:focus{
      border-color: rgba(14,122,67,.35);
      box-shadow: 0 0 0 4px rgba(22,163,74,.10);
    }

    .row{ display:flex; gap: 12px; flex-wrap:wrap; align-items:center; }
    .row .btn{ width: 100%; }

    .notice{
      border-radius: 18px;
      padding: 12px 14px;
      border: 1px solid rgba(11,27,20,.12);
      background:#fff;
      margin-bottom: 12px;
      font-weight: 750;
      color: rgba(11,27,20,.74);
    }
    .notice--err{ background: rgba(255, 70, 70, .06); border-color: rgba(255, 70, 70, .22); }
    .notice--ok{ background: rgba(22,163,74,.08); border-color: rgba(22,163,74,.22); }

    .auth-side{
      border-radius: var(--radius);
      border: 2px solid rgba(11,27,20,.16);
      background:
        radial-gradient(680px 260px at 70% 40%, rgba(22,163,74,.18), transparent 60%),
        linear-gradient(180deg, rgba(14,122,67,.06), rgba(255,255,255, .00));
      box-shadow: var(--shadow);
      padding: 22px;
      overflow:hidden;
    }
    .auth-side .auth-title{ font-size: 32px; }
    .auth-side ul{ margin: 0; padding-left: 18px; color: rgba(11,27,20,.72); font-weight: 650; line-height:1.45; }
    .auth-side li{ margin: 10px 0; }

    @media (max-width: 560px){
      .header__inner{ gap: 10px; }
      .brand__logo{ width: 120px; }
      .header__actions .btn{
        padding: 10px 14px;
        font-size: 14px;
        border-radius: 999px;
        white-space: nowrap;
      }
    }

    @media (max-width: 980px){
      .auth-wrap{ grid-template-columns: 1fr; }
    }

    /* ✅ ДОДАНО: OAuth блок */
    .oauth-sep{
      display:flex;
      align-items:center;
      gap:12px;
      margin: 14px 0 10px;
      color: rgba(11,27,20,.55);
      font-weight: 800;
    }
    .oauth-sep:before,
    .oauth-sep:after{
      content:"";
      height:1px;
      flex:1;
      background: rgba(11,27,20,.12);
    }
    .btn-oauth{
      width:100%;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:10px;
      padding: 12px 14px;
      border-radius: 16px;
      border: 1px solid rgba(11,27,20,.14);
      background:#fff;
      font-weight: 900;
      text-decoration:none;
      color: rgba(11,27,20,.92);
      box-shadow: 0 6px 18px rgba(0,0,0,.05);
    }
    .btn-oauth:hover{
      border-color: rgba(14,122,67,.22);
      box-shadow: 0 10px 26px rgba(0,0,0,.07);
      transform: translateY(-1px);
    }
    .btn-oauth__icon{
      width:18px;
      height:18px;
      display:inline-block;
    }
  </style>
</head>

<body>
  <header class="header">
    <div class="container header__inner">
      <a class="brand" href="/" aria-label="На головну">
        <img class="brand__logo" src="/assets/img/logo.svg" alt="ProstoPDR" />
      </a>
      <div class="header__actions">
        <a class="btn btn--primary" href="/">на головну</a>
      </div>
    </div>
  </header>

  <main class="auth-page">
    <div class="container">
      <h1 class="h2">Вхід / Реєстрація</h1>
      <p class="lead">Зайди в акаунт, щоб бачити прогрес, статистику та повторювати помилки.</p>

      <?php if ($err): ?>
        <div class="notice notice--err"><?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>
      <?php if ($ok): ?>
        <div class="notice notice--ok"><?php echo htmlspecialchars($ok, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>

      <div class="auth-wrap">
        <section class="auth-card">
          <div class="tabs">
            <button class="tab is-active" type="button" data-tab="login">Увійти</button>
            <button class="tab" type="button" data-tab="register">Зареєструватись</button>
          </div>

          <h2 class="auth-title" id="formTitle">Увійти</h2>
          <p class="auth-sub" id="formSub">Введи email і пароль, щоб продовжити підготовку.</p>

          <form method="post" action="/auth/email.php" id="authForm" novalidate>
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="mode" value="login" id="mode">

            <div class="field" id="nameField" style="display:none;">
              <div class="label">Імʼя (необовʼязково)</div>
              <input class="input" type="text" name="name" autocomplete="name" placeholder="Наприклад: Андрій">
            </div>

            <div class="field">
              <div class="label">Email</div>
              <input class="input" type="email" name="email" autocomplete="email" required placeholder="you@gmail.com">
            </div>

            <div class="field">
              <div class="label">Пароль</div>
              <input class="input" type="password" name="password" autocomplete="current-password" required placeholder="Мінімум 8 символів">
            </div>

            <div class="field" id="confirmField" style="display:none;">
              <div class="label">Повтори пароль</div>
              <input class="input" type="password" name="password_confirm" autocomplete="new-password" placeholder="Повтори пароль">
            </div>

            <div class="row">
              <button class="btn btn--primary btn--xl" type="submit" id="submitBtn">Увійти</button>
            </div>
          </form>

          <!-- ✅ ДОДАНО: Вхід через Google -->
          <div class="oauth-sep">або</div>
          <div class="row">
            <a class="btn-oauth" href="/auth/google/start.php" aria-label="Увійти через Google">
              <!-- inline Google icon (простий) -->
              <svg class="btn-oauth__icon" viewBox="0 0 48 48" aria-hidden="true">
                <path fill="#FFC107" d="M43.611 20.083H42V20H24v8h11.303C33.652 32.659 29.215 36 24 36c-6.627 0-12-5.373-12-12s5.373-12 12-12c3.059 0 5.842 1.154 7.957 3.043l5.657-5.657C34.046 6.053 29.268 4 24 4 12.955 4 4 12.955 4 24s8.955 20 20 20 20-8.955 20-20c0-1.341-.138-2.65-.389-3.917z"/>
                <path fill="#FF3D00" d="M6.306 14.691l6.571 4.819C14.655 16.108 19.001 12 24 12c3.059 0 5.842 1.154 7.957 3.043l5.657-5.657C34.046 6.053 29.268 4 24 4c-7.682 0-14.39 4.326-17.694 10.691z"/>
                <path fill="#4CAF50" d="M24 44c5.115 0 9.814-1.963 13.351-5.165l-6.166-5.218C29.169 35.091 26.715 36 24 36c-5.193 0-9.615-3.317-11.273-7.946l-6.52 5.026C9.463 39.556 16.227 44 24 44z"/>
                <path fill="#1976D2" d="M43.611 20.083H42V20H24v8h11.303c-.792 2.251-2.244 4.153-4.118 5.617l.003-.002 6.166 5.218C36.9 39.233 44 34 44 24c0-1.341-.138-2.65-.389-3.917z"/>
              </svg>
              Увійти через Google
            </a>
          </div>

        </section>

        <aside class="auth-side">
          <h2 class="auth-title">Що дає акаунт ProstoPDR</h2>
          <p class="auth-sub">Після входу ти отримуєш персональний прогрес і повторення помилок.</p>
          <ul>
            <li>Статистика прогресу по днях</li>
            <li>Повторення помилок і слабких тем</li>
            <li>Режим «іспит» з таймером</li>
            <li>Пояснення до питань</li>
            <li>Тестовий доступ 3 дні</li>
          </ul>
        </aside>
      </div>
    </div>
  </main>

  <script>
    (function(){
      const tabs = Array.from(document.querySelectorAll('[data-tab]'));
      const title = document.getElementById('formTitle');
      const sub = document.getElementById('formSub');
      const mode = document.getElementById('mode');
      const nameField = document.getElementById('nameField');
      const confirmField = document.getElementById('confirmField');
      const submitBtn = document.getElementById('submitBtn');

      function setTab(key){
        tabs.forEach(t => t.classList.toggle('is-active', t.dataset.tab === key));

        if(key === 'register'){
          title.textContent = 'Реєстрація';
          sub.textContent = 'Створи акаунт: email + пароль.';
          mode.value = 'register';
          nameField.style.display = '';
          confirmField.style.display = '';
          submitBtn.textContent = 'Зареєструватись';
        }else{
          title.textContent = 'Увійти';
          sub.textContent = 'Введи email і пароль, щоб продовжити підготовку.';
          mode.value = 'login';
          nameField.style.display = 'none';
          confirmField.style.display = 'none';
          submitBtn.textContent = 'Увійти';
        }
      }

      tabs.forEach(btn => btn.addEventListener('click', () => setTab(btn.dataset.tab)));

      const url = new URL(window.location.href);
      if (url.searchParams.get('tab') === 'register') setTab('register');
    })();
  </script>
</body>
</html>