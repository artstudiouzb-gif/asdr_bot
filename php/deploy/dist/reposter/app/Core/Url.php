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

    public static function to(string $path): string
    {
        if ($path === '' || $path[0] !== '/') {
            return $path;                       // внешние ссылки не трогаем
        }
        return self::$base . $path;
    }
}
