<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/ref_store.php';

ref_ensure_schema();

$code = $_GET['c'] ?? $_GET['ref'] ?? '';
if (ref_capture_code_from_request((string)$code)) {
    header('Location: /login');
    exit;
}

header('Location: /');
exit;