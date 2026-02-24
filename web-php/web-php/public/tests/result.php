<?php
// public/tests/result.php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';

$user = require_login();

$attemptId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$attemptId) { http_response_code(400); die("Bad id"); }

$pdo = db();
$stmt = $pdo->prepare("
  SELECT a.*, ts.title AS test_title, ts.type, ts.time_limit_sec, ts.max_mistakes, tp.title AS topic_title
  FROM attempts a
  JOIN tests ts ON ts.id = a.test_id
  JOIN topics tp ON tp.id = ts.topic_id
  WHERE a.id = ? AND a.user_id = ?
");
$stmt->execute([$attemptId, (int)$user['id']]);
$row = $stmt->fetch();
if (!$row) { http_response_code(404); die("Not found"); }

$spent = (int)$row['time_spent_sec'];
$spentFmt = sprintf('%02d:%02d', intdiv($spent,60), $spent%60);
?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="/style.css">
  <title>Результат</title>
</head>
<body>
  <div class="container" style="padding:34px 0;">
    <div class="account-card">
      <h2 class="h2" style="margin-bottom:6px;">Результат</h2>
      <p class="lead" style="margin:0;"><?php echo htmlspecialchars($row['topic_title']); ?> · <?php echo htmlspecialchars($row['test_title']); ?></p>

      <div style="height:14px"></div>

      <div class="sub-card" style="background:#fff;">
        <div class="sub-card__row">
          <div class="sub-card__label">Правильні</div>
          <div class="sub-card__value"><?php echo (int)$row['score_correct']; ?></div>
        </div>
        <div class="sub-card__row">
          <div class="sub-card__label">Помилки</div>
          <div class="sub-card__value"><?php echo (int)$row['score_wrong']; ?> / <?php echo (int)$row['max_mistakes']; ?></div>
        </div>
        <div class="sub-card__row">
          <div class="sub-card__label">Час</div>
          <div class="sub-card__value"><?php echo $spentFmt; ?></div>
        </div>
        <div class="sub-card__row">
          <div class="sub-card__label">Статус</div>
          <div class="sub-card__value">
            <?php if ((int)$row['is_passed'] === 1): ?>
              ✅ Зараховано
            <?php else: ?>
              <?php if ((int)$row['score_correct'] === 0 && (int)$row['score_wrong'] === 0): ?>
                ⏳ Ключі відповідей ще не додані (результат тимчасовий)
              <?php else: ?>
                ❌ Не зараховано
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div style="height:14px"></div>

      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <a class="btn btn--primary" href="/tests/">До тестів</a>
        <a class="btn btn--ghost" href="/account/">В кабінет</a>
      </div>
    </div>
  </div>
</body>
</html>
