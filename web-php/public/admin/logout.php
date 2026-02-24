<?php
declare(strict_types=1);
require_once __DIR__ . '/_guard.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
  @session_start();
}

unset($_SESSION['is_admin']);
header('Location: /admin/login.php', true, 302);
exit;
