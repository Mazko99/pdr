<?php
// web-php/public/router.php

$uri  = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$path = rtrim($uri, '/');

// дефолт
if ($path === '') $path = '/';

// повний шлях до файла в public
$file = __DIR__ . $path;

// 1) Якщо запит на існуючий файл (css/js/png/pdf/...) — віддаємо напряму
if ($path !== '/' && is_file($file)) {
    return false;
}

// 2) Якщо запит на існуючий PHP-файл — виконуємо його напряму
// Напр: /account/quiz.php -> public/account/quiz.php
if ($path !== '/' && is_file($file . '.php')) {
    require $file . '.php';
    return true;
}
if ($path !== '/' && str_ends_with($path, '.php') && is_file($file)) {
    require $file;
    return true;
}

// 3) Якщо є директорія і в ній index.php — відкриваємо її
// Напр: /account -> public/account/index.php
if ($path !== '/' && is_dir($file) && is_file($file . '/index.php')) {
    require $file . '/index.php';
    return true;
}

// 4) Інакше — fallback на головний index.php (якщо в тебе є маршрутизація)
require __DIR__ . '/index.php';