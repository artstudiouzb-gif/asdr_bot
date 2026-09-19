<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Подписи и шифрование на openssl — ext-sodium на шаред-хостинге может отсутствовать.
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';

    public static function key(): string
    {
        $key = Env::get('APP_KEY', '');
        if ($key === null || strlen($key) < 32) {
            throw new \RuntimeException('APP_KEY в .env короче 32 символов — выполните php bin/genkey.php');
        }
        return hash('sha256', $key, true);
    }

    public static function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, self::key());
    }

    public static function verify(string $payload, string $signature): bool
    {
        return hash_equals(self::sign($payload), $signature);
    }

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Не удалось зашифровать значение');
        }
        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $encoded): ?string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? null : $plain;
    }

    public static function randomToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }
}
