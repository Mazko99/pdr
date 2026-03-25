<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/ref_store.php';

ref_ensure_schema();

$email = $argv[1] ?? '';
$password = $argv[2] ?? '';
$name = $argv[3] ?? '';

if ($email === '' || $password === '') {
    echo "Usage:\n";
    echo "php tools/create_ref_account.php partner@example.com StrongPass123 \"Partner Name\"\n";
    exit(1);
}

try {
    $acc = ref_create_account($email, $password, $name);
    echo "Created referral account:\n";
    echo "Email: " . $acc['email'] . "\n";
    echo "Name: " . $acc['name'] . "\n";
    echo "Code: " . $acc['ref_code'] . "\n";
    echo "Link: " . ref_link_for_account($acc) . "\n";
    exit(0);
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}