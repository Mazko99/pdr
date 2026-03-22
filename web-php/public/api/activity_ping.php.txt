<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/activity_store.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

$uid = auth_user_id();
if (!$uid) {
    http_response_code(401);
    echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
    exit;
}

$page = trim((string)($_POST['page'] ?? ''));
if ($page === '') {
    $page = '/';
}

activity_ping((string)$uid, $page);

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);