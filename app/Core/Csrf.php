<?php

declare(strict_types=1);

namespace App\Core;

/** CSRF-токен, привязанный к сессионной cookie (состояние хранить не нужно). */
final class Csrf
{
    public static function token(string $sessionToken): string
    {
        return Crypto::sign('csrf:' . $sessionToken);
    }

    public static function check(string $given, string $sessionToken): bool
    {
        if ($given === '') {
            return false;
        }
        return hash_equals(self::token($sessionToken), $given);
    }

    public static function field(string $sessionToken): string
    {
        return '<input type="hidden" name="csrf" value="' . htmlspecialchars(self::token($sessionToken), ENT_QUOTES) . '">';
    }
}
