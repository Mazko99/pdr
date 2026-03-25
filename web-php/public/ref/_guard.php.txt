<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/ref_store.php';

ref_ensure_schema();

$refUser = ref_auth_user();
if (!$refUser) {
    header('Location: /ref/login.php');
    exit;
}