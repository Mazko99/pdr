<?php
declare(strict_types=1);
/** @var string $title */
/** @var string $content */
?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="stylesheet" href="/assets/css/app.css" />
</head>
<body>
  <?= $content ?>
  <script src="/assets/js/app.js"></script>
</body>
</html>
