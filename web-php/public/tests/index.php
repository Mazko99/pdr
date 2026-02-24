<?php
// public/tests/index.php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/tests_repo.php';

$user = require_login();

if (!has_active_subscription($user)) {
  http_response_code(403);
  ?>
  <!doctype html><html lang="uk"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="/style.css">
    <title>Тести — доступ закрито</title>
  </head><body>
    <div class="container" style="padding:34px 0;">
      <div class="account-card">
        <h2 class="h3">Доступ до тестів закрито</h2>
        <p class="lead" style="margin:0;">Обери тариф у кабінеті — після оплати тут зʼявляться всі теми, тести та іспити.</p>
        <div style="height:14px"></div>
        <a class="btn btn--primary" href="/account/">Перейти в кабінет</a>
      </div>
    </div>
  </body></html>
  <?php
  exit;
}

$topics = get_topics_with_tests();
?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="/style.css">
  <title>Тести та іспити</title>
</head>
<body>
  <div class="container" style="padding:34px 0;">
    <div class="account-card">
      <h2 class="h2" style="margin-bottom:8px;">Тести та іспити</h2>
      <p class="lead" style="margin:0;">Обери тему → проходь тести по 20 питань, максимум 2 помилки. Для кожної теми є контрольний іспит на 40 питань (якщо в темі ≥ 40).</p>
    </div>

    <div style="height:16px"></div>

    <?php foreach ($topics as $t): ?>
      <div class="account-card" style="margin-top:14px;">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;">
          <div>
            <h3 class="h3" style="margin:0 0 6px;"><?php echo htmlspecialchars($t['title']); ?></h3>
            <div class="lead" style="margin:0;font-size:14px;">Питань у темі: <b><?php echo (int)$t['question_count']; ?></b></div>
          </div>
          <a class="btn btn--ghost" href="/tests/topic.php?slug=<?php echo urlencode($t['slug']); ?>">Відкрити</a>
        </div>

        <?php if (!empty($t['tests'])): ?>
          <div style="height:12px"></div>
          <div class="pricing" style="grid-template-columns: 1fr; gap:10px;">
            <?php foreach ($t['tests'] as $ts): ?>
              <div class="sub-card" style="background:#fff;">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                  <div style="font-weight:900;">
                    <?php echo htmlspecialchars($ts['title']); ?>
                    <span style="opacity:.65;font-weight:800;">· <?php echo ($ts['type']==='exam'?'Іспит':'Тест'); ?></span>
                  </div>
                  <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <span class="pill">⏱ <?php echo (int)($ts['time_limit_sec']/60); ?> хв</span>
                    <span class="pill">❌ max <?php echo (int)$ts['max_mistakes']; ?></span>
                    <a class="btn btn--primary" href="/tests/run.php?id=<?php echo (int)$ts['id']; ?>">Почати</a>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

  </div>
</body>
</html>
