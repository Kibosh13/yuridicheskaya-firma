<?php

declare(strict_types=1);

require __DIR__ . '/cms/runtime.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

try {
    echo cms_render(__DIR__ . '/index.html', 'home');
} catch (Throwable $error) {
    error_log($error->__toString());
    @file_put_contents(cms_storage_dir() . '/error.log', gmdate('c') . "\n" . $error->__toString() . "\n\n", FILE_APPEND | LOCK_EX);
    http_response_code(500);
    echo 'Страница временно недоступна.';
}
