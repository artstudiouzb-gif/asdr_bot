<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

// тестовые значения окружения, реальный .env не нужен
$reflection = new ReflectionClass(App\Core\Env::class);
$values = $reflection->getProperty('values');
$values->setValue(null, [
    'APP_KEY'  => str_repeat('k', 64),
    'APP_ENV'  => 'testing',
    'TIMEZONE' => 'Asia/Tashkent',
    'LOG_LEVEL' => 'error',
]);
