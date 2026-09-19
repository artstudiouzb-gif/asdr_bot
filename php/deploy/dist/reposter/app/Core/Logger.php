<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

final class Logger
{
    private const LEVELS = ['debug' => 10, 'info' => 20, 'warning' => 30, 'error' => 40];
    private const SECRET_KEYS = ['token', 'password', 'api_hash', 'phone', 'code', 'session'];

    public static function debug(string $component, string $message, array $context = []): void
    {
        self::write('debug', $component, $message, $context);
    }

    public static function info(string $component, string $message, array $context = []): void
    {
        self::write('info', $component, $message, $context);
    }

    public static function warning(string $component, string $message, array $context = []): void
    {
        self::write('warning', $component, $message, $context);
    }

    public static function error(string $component, string $message, array $context = []): void
    {
        self::write('error', $component, $message, $context);
    }

    public static function write(string $level, string $component, string $message, array $context = []): void
    {
        $minimum = self::LEVELS[strtolower((string)Env::get('LOG_LEVEL', 'info'))] ?? 20;
        if ((self::LEVELS[$level] ?? 20) < $minimum) {
            return;
        }
        $context = self::mask($context);
        try {
            Db::insert('logs', [
                'level'          => $level,
                'component'      => $component,
                'publication_id' => $context['publication_id'] ?? null,
                'source_id'      => $context['source_id'] ?? null,
                'destination_id' => $context['destination_id'] ?? null,
                'message'        => mb_substr($message, 0, 2000),
                'context'        => $context === [] ? null : json_encode($context, JSON_UNESCAPED_UNICODE),
                'created_at'     => Db::now(),
            ]);
        } catch (Throwable $e) {
            self::toFile($level, $component, $message . ' | лог в БД недоступен: ' . $e->getMessage());
        }
    }

    public static function toFile(string $level, string $component, string $message): void
    {
        $dir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        @file_put_contents(
            $dir . '/app-' . gmdate('Y-m-d') . '.log',
            sprintf("%s [%s] %s: %s\n", gmdate('Y-m-d H:i:s'), strtoupper($level), $component, $message),
            FILE_APPEND | LOCK_EX
        );
    }

    /** Токены и телефоны не должны попадать в логи. */
    private static function mask(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $context[$key] = self::mask($value);
                continue;
            }
            foreach (self::SECRET_KEYS as $secret) {
                if (stripos((string)$key, $secret) !== false && is_string($value) && $value !== '') {
                    $context[$key] = mb_strlen($value) > 8
                        ? mb_substr($value, 0, 3) . '…' . mb_substr($value, -2)
                        : '…';
                }
            }
        }
        return $context;
    }
}
