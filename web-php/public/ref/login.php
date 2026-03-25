<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/ref_store.php';

ref_ensure_schema();

if (ref_auth_user()) {
    header('Location: /ref/index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $acc = ref_verify_password($email, $password);
    if ($acc) {
        ref_login($acc);
        header('Location: /ref/index.php');
        exit;
    }

    $error = 'Невірний email або пароль.';
}
?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Referral Login — ProstoPDR</title>
  <style>
    body{margin:0;background:#f5f7f6;font-family:Manrope,Arial,sans-serif;color:#0b1b12}
    .wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
    .card{width:100%;max-width:460px;background:#fff;border:1px solid rgba(11,27,18,.08);border-radius:24px;padding:24px;box-shadow:0 18px 50px rgba(11,27,18,.08)}
    h1{margin:0 0 10px;font-size:28px;line-height:1.1}
    p{margin:0 0 18px;color:#5f6f67}
    label{display:block;margin:0 0 8px;font-size:14px;font-weight:700}
    input{width:100%;box-sizing:border-box;height:48px;border-radius:14px;border:1px solid #d9e3dc;padding:0 14px;margin:0 0 14px;font-size:15px}
    button{width:100%;height:50px;border:0;border-radius:16px;background:#0a6b35;color:#fff;font-size:16px;font-weight:800;cursor:pointer}
    .err{margin:0 0 14px;padding:12px 14px;border-radius:14px;background:#fff1f1;color:#a11d1d;border:1px solid #f4caca}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h1>Referral кабінет</h1>
      <p>Окремий вхід для партнера з реферальною ссилкою та списком реєстрацій.</p>

      <?php if ($error !== ''): ?>
        <div class="err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <form method="post">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" required>

        <label for="password">Пароль</label>
        <input id="password" name="password" type="password" required>

        <button type="submit">Увійти</button>
      </form>
    </div>
  </div>
</body>
</html>