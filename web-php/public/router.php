<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;

// віддати статичний файл напряму
if ($path !== '/' && is_file($file)) {
    return false;
}

// інакше — твій фронт-контролер
require __DIR__ . '/index.php';