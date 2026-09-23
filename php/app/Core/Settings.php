<?php

declare(strict_types=1);

namespace App\Core;

/** Настройки из таблицы settings со значениями по умолчанию. */
final class Settings
{
    public const DEFAULTS = [
        'retry_max'             => '4',
        'backfill_on_first_run' => '0',
        'publish_batch'         => '20',
    ];

    public static function get(string $key): string
    {
        $value = Db::value('SELECT `value` FROM settings WHERE `key` = ?', [$key]);
        return $value === null ? (self::DEFAULTS[$key] ?? '') : (string)$value;
    }

    public static function int(string $key): int
    {
        return (int)self::get($key);
    }

    public static function set(string $key, string $value): void
    {
        Db::run(
            'INSERT INTO settings (`key`, `value`, `updated_at`) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated_at` = VALUES(`updated_at`)',
            [$key, $value, Db::now()]
        );
    }

    /** @return array<string, string> */
    public static function all(): array
    {
        $values = self::DEFAULTS;
        foreach (Db::all('SELECT `key`, `value` FROM settings') as $row) {
            $values[(string)$row['key']] = (string)$row['value'];
        }
        return $values;
    }
}
