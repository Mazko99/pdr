<?php

$path = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = __DIR__ . $path;

// DEBUG (можеш потім прибрати)
// error_log("Router request: " . $path);

// якщо файл існує — віддати його напряму
if ($path !== '/' && file_exists($file) && !is_dir($file)) {
    return false;
}

// інакше — index.php
require __DIR__ . '/index.php';