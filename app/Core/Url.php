<?php

declare(strict_types=1);

namespace App\Core;

/** Базовый путь панели: она может лежать и в корне домена, и в подпапке /panel. */
final class Url
{
    private static string $base = '';

    public static function setBase(string $base): void
    {
        self::$base = $base === '/' ? '' : rtrim($base, '/');
    }

    public static function base(): string
    {
        return self::$base;
    }

    /** Базовый путь из SCRIPT_NAME: /panel/index.php → /panel, /bot/install.php → /bot */
    public static function detect(array $server): string
    {
        $script = (string)($server['SCRIPT_NAME'] ?? '');
        $base = rtrim(str_contains($script, '.php') ? dirname($script) : $script, '/');
        return $base === '.' || $base === '/' ? '' : $base;
    }

    public static function to(string $path): string
    {
        if ($path === '' || $path[0] !== '/') {
            return $path;                       // внешние ссылки не трогаем
        }
        return self::$base . $path;
    }
}
