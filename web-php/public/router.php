<?php
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

$fullPath = __DIR__ . $uri;

// якщо існує файл (css/js/png/svg/...) — віддай напряму
if ($uri !== '/' && is_file($fullPath)) {
    return false;
}

// якщо існує PHP файл — виконай його
if ($uri !== '/' && is_file($fullPath) && str_ends_with($uri, '.php')) {
    require $fullPath;
    return true;
}

// якщо запит без .php, але існує file.php — виконай
if ($uri !== '/' && is_file($fullPath . '.php')) {
    require $fullPath . '.php';
    return true;
}

// fallback
require __DIR__ . '/index.php';