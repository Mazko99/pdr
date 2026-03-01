<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/mono_payments_store.php';
require_once __DIR__ . '/../../src/mono_acquiring.php';

if (session_status() !== PHP_SESSION_ACTIVE) @session_start();

$orderRef = (string)($_GET['invoice'] ?? '');
$title = 'Оплата';
$msg = 'Дякуємо! Перевіряємо статус...';

?><!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($title) ?> — ProstoPDR</title>
  <link rel="stylesheet" href="/assets/css/style.css?v=4">
</head>
<body>
<main class="section section--soft" style="padding-top:46px;">
  <div class="container" style="max-width:760px;">
    <div class="account-card">
      <h1 class="h2">Оплата</h1>
      <p class="lead" id="statusText"><?= htmlspecialchars($msg) ?></p>

      <div style="height:14px"></div>
      <a class="btn btn--primary" href="/account?tab=dashboard">В кабінет</a>
      <a class="btn btn--ghost" href="/">На головну</a>
    </div>
  </div>
</main>

<script>
(async function(){
  const el = document.getElementById('statusText');
  // просто UX: вебхук може прийти з затримкою
  el.textContent = 'Оплата може оброблятись 5–20 секунд. Якщо доступ не з’явився — онови сторінку кабінету.';
})();
</script>
</body>
</html>