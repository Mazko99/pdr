<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/ref_store.php';

ref_ensure_schema();

$code = strtoupper(trim((string)($_GET['c'] ?? $_GET['ref'] ?? '')));

if ($code !== '') {
    ref_capture_code_from_request($code);
    header('Location: /login?ref=' . rawurlencode($code));
    exit;
}

header('Location: /login');
exit;