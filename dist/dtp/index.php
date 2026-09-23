<?php

declare(strict_types=1);

require dirname(__DIR__) . '/cms/runtime.php';

try {
    echo cms_render(__DIR__ . '/index.html', 'dtp');
} catch (Throwable $error) {
    error_log($error->__toString());
    @file_put_contents(cms_storage_dir() . '/error.log', gmdate('c') . "\n" . $error->__toString() . "\n\n", FILE_APPEND | LOCK_EX);
    http_response_code(500);
    echo 'Страница временно недоступна.';
}
