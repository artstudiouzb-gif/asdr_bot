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
if (PHP_SAPI !== 'cli') {
    App\Core\Url::setBase(App\Core\Url::detect($_SERVER));   // нужно и install.php, и cron.php
}
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

    $reason = App\Core\Diagnostics::classify($e);
    $details = App\Core\Diagnostics::debugAllowed()
        ? $e->getMessage() . "\n\n" . $e->getFile() . ':' . $e->getLine()
          . "\n\n" . App\Core\Diagnostics::tailLog()
        : null;

    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $base = App\Core\Url::base();

    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1"><title>Ошибка</title>';
    echo '<style>body{font:15px/1.6 -apple-system,Segoe UI,Roboto,sans-serif;background:#f5f6fa;color:#0f172a;'
       . 'margin:0;padding:40px 16px}.box{max-width:640px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;'
       . 'border-radius:14px;padding:24px}h1{font-size:19px;margin:0 0 10px}p{margin:8px 0}'
       . 'a{color:#2563eb}pre{background:#0f172a;color:#e2e8f0;padding:12px;border-radius:10px;overflow:auto;'
       . 'font-size:12.5px;white-space:pre-wrap}</style></head><body><div class="box">';
    echo '<h1>' . $escape($reason['title']) . '</h1>';
    echo '<p>' . $escape($reason['hint']) . '</p>';
    if ($reason['action'] === 'install') {
        echo '<p><a href="' . $escape($base . '/install.php') . '">Открыть установку и настройку →</a></p>';
    }
    if ($details !== null) {
        echo '<pre>' . $escape($details) . '</pre>';
    } else {
        echo '<p style="color:#64748b;font-size:13px">Чтобы увидеть подробности, добавьте к адресу '
           . '<code>?debug=ЗНАЧЕНИЕ_INSTALL_KEY</code> из файла .env.</p>';
    }
    echo '</div></body></html>';
});

/** Ссылка с учётом подпапки, в которой стоит панель. */
function url(string $path): string
{
    return App\Core\Url::to($path);
}

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
