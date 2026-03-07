<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/progress_store.php';
require_once __DIR__ . '/../../src/users_store.php';

if (!auth_user_id()) {
    header('Location: /login', true, 302);
    exit;
}

$uid = (string)auth_user_id();
$user = function_exists('user_find_by_id') ? user_find_by_id($uid) : null;
$prog = progress_user_get($uid);

$logFile = dirname(__DIR__, 2) . '/storage/progress_debug.log';
$logText = is_file($logFile) ? (string)file_get_contents($logFile) : 'LOG FILE NOT FOUND';

header('Content-Type: text/plain; charset=utf-8');

echo "=== AUTH ===\n";
print_r([
    'auth_user_id' => $uid,
    'session_user_id' => $_SESSION['user_id'] ?? null,
    'session_uid' => $_SESSION['uid'] ?? null,
    'session_has_access' => $_SESSION['has_access'] ?? null,
]);

echo "\n=== USER ===\n";
print_r($user);

echo "\n=== PROGRESS_USER_GET ===\n";
print_r($prog);

echo "\n=== LOG ===\n";
echo $logText;
