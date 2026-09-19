<?php

declare(strict_types=1);

use App\Core\Env;
use App\Core\Logger;

define('APP_ROOT', dirname(__DIR__));

$composer = APP_ROOT . '/vendor/autoload.php';
if (is_file($composer)) {
    require $composer;
} else {
    // Панель должна работать и до загрузки vendor/ (он нужен только для Telegram)
    spl_autoload_register(static function (string $class): void {
        if (!str_starts_with($class, 'App\\')) {
            return;
        }
        $path = APP_ROOT . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) {
            require $path;
        }
    });
}

Env::load(APP_ROOT . '/.env');
date_default_timezone_set('UTC');               // всё храним в UTC, показываем в TIMEZONE
mb_internal_encoding('UTF-8');

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $e): void {
    Logger::toFile('error', 'app', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Ошибка: ' . $e->getMessage() . "\n");
        exit(1);
    }
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    $debug = Env::get('APP_ENV', 'production') !== 'production';
    echo '<h1>500 — внутренняя ошибка</h1>';
    echo $debug
        ? '<pre>' . htmlspecialchars($e->getMessage() . "\n\n" . $e->getTraceAsString(), ENT_QUOTES) . '</pre>'
        : '<p>Подробности записаны в журнал.</p>';
});

/** Экранирование для шаблонов. */
function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Время UTC → часовой пояс из настроек, для вывода в панели. */
function display_time(?string $utc, string $format = 'd.m.Y H:i'): string
{
    if ($utc === null || $utc === '') {
        return '—';
    }
    $zone = Env::get('TIMEZONE', 'Asia/Tashkent') ?? 'Asia/Tashkent';
    try {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone($zone))->format($format);
    } catch (Throwable) {
        return $utc;
    }
}
