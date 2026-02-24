<?php
// web-php/public/router.php

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// якщо запит на існуючий файл (css/js/img/svg/...)
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file)) {
    return false; // віддати файл напряму
}

// інакше — запускаємо фронт-контролер
require __DIR__ . '/index.php';