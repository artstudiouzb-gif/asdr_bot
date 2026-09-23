<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Превращает исключение в понятную причину и подсказку.
 * Секреты (логины, пароли, хосты) в текст не попадают.
 */
final class Diagnostics
{
    /** @return array{title:string, hint:string, action:?string} */
    public static function classify(Throwable $e): array
    {
        $message = $e->getMessage();
        $envMissing = !is_file(APP_ROOT . '/.env');

        if ($envMissing) {
            return [
                'title'  => 'Проект ещё не настроен',
                'hint'   => 'Рядом с каталогом app/ нет файла .env — значит, установка не выполнялась.',
                'action' => 'install',
            ];
        }
        if (str_contains($message, 'APP_KEY')) {
            return [
                'title'  => 'В .env не задан APP_KEY',
                'hint'   => 'Нужна случайная строка длиной не меньше 32 символов.',
                'action' => 'install',
            ];
        }
        if (str_contains($message, 'В .env не задан')) {
            return [
                'title'  => trim($message),
                'hint'   => 'Заполните недостающие поля в файле .env.',
                'action' => 'install',
            ];
        }
        if (str_contains($message, 'SQLSTATE[HY000] [1045]') || str_contains($message, 'Access denied')) {
            return [
                'title'  => 'База данных отказала в доступе',
                'hint'   => 'Проверьте DB_USER и DB_PASS в .env — пользователь должен быть привязан к базе.',
                'action' => 'install',
            ];
        }
        if (str_contains($message, 'SQLSTATE[HY000] [1049]') || str_contains($message, 'Unknown database')) {
            return [
                'title'  => 'Такой базы данных нет',
                'hint'   => 'Проверьте DB_NAME в .env — имя базы на хостинге обычно с префиксом (uXXXX_name).',
                'action' => 'install',
            ];
        }
        if (str_contains($message, 'SQLSTATE[HY000] [2002]') || str_contains($message, 'Connection refused')
            || str_contains($message, 'No such file or directory')) {
            return [
                'title'  => 'Нет связи с сервером базы данных',
                'hint'   => 'Проверьте DB_HOST в .env: на большинстве хостингов это localhost или 127.0.0.1.',
                'action' => 'install',
            ];
        }
        if (str_contains($message, "Base table or view not found") || str_contains($message, '42S02')) {
            return [
                'title'  => 'В базе нет таблиц репостера',
                'hint'   => 'Не применены миграции.',
                'action' => 'install',
            ];
        }
        if (str_contains($message, 'Permission denied') || str_contains($message, 'failed to open stream')) {
            return [
                'title'  => 'Нет прав на запись',
                'hint'   => 'Каталогу storage/ нужны права на запись (755 или 775).',
                'action' => null,
            ];
        }
        return [
            'title'  => 'Внутренняя ошибка',
            'hint'   => 'Подробности — в журнале storage/logs/.',
            'action' => null,
        ];
    }

    /** Последние строки журнала — показываются только по ключу установки. */
    public static function tailLog(int $lines = 15): string
    {
        $files = glob(APP_ROOT . '/storage/logs/app-*.log') ?: [];
        if ($files === []) {
            return 'Журнал пуст.';
        }
        rsort($files);
        $content = @file($files[0], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        return implode("\n", array_slice($content, -$lines));
    }

    /** Показывать подробности можно, если это явно разрешено ключом установки. */
    public static function debugAllowed(): bool
    {
        $key = (string)($_GET['debug'] ?? '');
        if ($key === '') {
            return (Env::get('APP_ENV', 'production') ?? 'production') !== 'production';
        }
        $expected = (string)(Env::get('INSTALL_KEY', '') ?? '');
        return $expected !== '' && hash_equals($expected, $key);
    }
}
