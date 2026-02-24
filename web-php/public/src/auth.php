<?php
// src/auth.php
declare(strict_types=1);

function require_login(): array {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  if (empty($_SESSION['user'])) {
    header('Location: /login.php');
    exit;
  }
  // Очікуємо, що в сесії лежить масив користувача: ['id'=>..., 'email'=>..., 'subscription_until'=>...]
  return (array)$_SESSION['user'];
}

function has_active_subscription(array $user): bool {
  // ✅ Підлаштуй під свою модель тарифів:
  // - якщо в тебе є поле subscription_until (YYYY-mm-dd HH:ii:ss)
  // - або is_paid / plan_id / тощо
  if (empty($user['subscription_until'])) return false;
  $ts = strtotime((string)$user['subscription_until']);
  if (!$ts) return false;
  return $ts > time();
}
