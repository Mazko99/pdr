<?php
declare(strict_types=1);

require_once __DIR__ . '/_guard.php';

$signups = ref_signups_list((string)$refUser['id'], 300);
$count = ref_signups_count((string)$refUser['id']);
$link = ref_link_for_account($refUser);
?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Referral Cabinet — ProstoPDR</title>
  <style>
    body{margin:0;background:#f5f7f6;font-family:Manrope,Arial,sans-serif;color:#0b1b12}
    .layout{max-width:1240px;margin:0 auto;padding:24px}
    .top{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:24px}
    .title{margin:0;font-size:34px;line-height:1.05;font-weight:900}
    .logout{display:inline-flex;align-items:center;justify-content:center;height:46px;padding:0 18px;border-radius:14px;background:#0a6b35;color:#fff;text-decoration:none;font-weight:800}
    .grid{display:grid;grid-template-columns:1.15fr .85fr;gap:20px;margin-bottom:20px}
    .card{background:#fff;border:1px solid rgba(11,27,18,.08);border-radius:24px;padding:22px;box-shadow:0 18px 50px rgba(11,27,18,.06)}
    .label{font-size:13px;font-weight:800;color:#658074;text-transform:uppercase;letter-spacing:.04em;margin:0 0 8px}
    .name{font-size:24px;font-weight:900;margin:0 0 10px}
    .muted{color:#607067}
    .refbox{display:flex;gap:10px;align-items:stretch;flex-wrap:wrap}
    .refbox input{flex:1 1 500px;height:50px;border:1px solid #d9e3dc;border-radius:14px;padding:0 14px;font-size:14px;background:#f9fbfa}
    .copy{height:50px;padding:0 18px;border:0;border-radius:14px;background:#0a6b35;color:#fff;font-weight:800;cursor:pointer}
    .kpi{font-size:40px;font-weight:900;line-height:1}
    table{width:100%;border-collapse:collapse}
    th,td{text-align:left;padding:14px 12px;border-bottom:1px solid #edf2ee;font-size:14px;vertical-align:top}
    th{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#6c7e75}
    .empty{padding:18px;border-radius:16px;background:#f8fbf8;color:#607067}
    @media (max-width: 980px){
      .grid{grid-template-columns:1fr}
      .top{flex-direction:column;align-items:flex-start}
    }
  </style>
</head>
<body>
  <div class="layout">
    <div class="top">
      <div>
        <h1 class="title">Referral кабінет</h1>
        <div class="muted">Партнер: <?= htmlspecialchars((string)($refUser['name'] ?: $refUser['email']), ENT_QUOTES, 'UTF-8') ?></div>
      </div>
      <a class="logout" href="/ref/logout.php">Вийти</a>
    </div>

    <div class="grid">
      <div class="card">
        <div class="label">Твоя реферальна ссилка</div>
        <div class="refbox">
          <input id="refLink" type="text" readonly value="<?= htmlspecialchars($link, ENT_QUOTES, 'UTF-8') ?>">
          <button class="copy" type="button" onclick="copyRefLink()">Скопіювати</button>
        </div>
        <div class="muted" style="margin-top:12px">
          Код: <strong><?= htmlspecialchars((string)$refUser['ref_code'], ENT_QUOTES, 'UTF-8') ?></strong>
        </div>
      </div>

      <div class="card">
        <div class="label">Всього реєстрацій</div>
        <div class="kpi"><?= (int)$count ?></div>
      </div>
    </div>

    <div class="card">
      <div class="label">Хто зареєструвався по ссилці</div>

      <?php if (!$signups): ?>
        <div class="empty">Поки що реєстрацій по цій ссилці немає.</div>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Ім’я</th>
              <th>Email</th>
              <th>План</th>
              <th>Оплачено</th>
              <th>Активний до</th>
              <th>Зареєстрований</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($signups as $row): ?>
              <tr>
                <td><?= htmlspecialchars((string)($row['name'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string)($row['email'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string)($row['plan'] ?: 'free'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string)($row['paid_at'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string)($row['expires_at'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string)($row['user_created_at'] ?: $row['referred_at'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

  <script>
    function copyRefLink() {
      var input = document.getElementById('refLink');
      input.select();
      input.setSelectionRange(0, 99999);
      navigator.clipboard.writeText(input.value).then(function () {
        alert('Ссилка скопійована');
      }).catch(function () {
        document.execCommand('copy');
        alert('Ссилка скопійована');
      });
    }
  </script>
</body>
</html>