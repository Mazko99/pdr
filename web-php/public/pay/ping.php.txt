<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
echo "PAY PING OK\n";
echo "URI=" . ($_SERVER['REQUEST_URI'] ?? '') . "\n";
exit;