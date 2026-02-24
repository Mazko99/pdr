<?php

$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$file = __DIR__ . $path;

// якщо це реальний файл (css/js/png/svg/jpg)
if ($path !== "/" && file_exists($file)) {
    return false;
}

// інакше запускаємо index.php
require __DIR__ . "/index.php";