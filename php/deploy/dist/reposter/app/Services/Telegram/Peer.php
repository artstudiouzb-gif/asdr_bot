<?php

declare(strict_types=1);

namespace App\Services\Telegram;

/** Приведение того, что вводит администратор, к виду, понятному Telegram. */
final class Peer
{
    /** «https://t.me/shmirziyoyev», «@channel», «-1001234567890» → «shmirziyoyev» / -1001234567890 */
    public static function normalize(string $value): string|int
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('/^-?\d+$/', $value) === 1) {
            return (int)$value;
        }

        $value = preg_replace('#^https?://#i', '', $value) ?? $value;
        foreach (['t.me/', 'telegram.me/', 'telegram.dog/'] as $host) {
            if (stripos($value, $host) === 0) {
                $value = substr($value, strlen($host));
                break;
            }
        }
        $value = ltrim($value, '@');
        if (stripos($value, 's/') === 0) {                          // вид t.me/s/channel
            $value = substr($value, 2);
        }
        $value = preg_replace('#[?/].*$#', '', $value) ?? $value;   // отбрасываем /35508 и ?query
        if (str_starts_with($value, '+') || stripos($value, 'joinchat') === 0) {
            return $value;                                          // приглашение в приватный канал
        }
        return $value;
    }

    public static function isValid(string $value): bool
    {
        $peer = self::normalize($value);
        if (is_int($peer)) {
            return $peer !== 0;
        }
        return preg_match('/^[A-Za-z][A-Za-z0-9_]{3,31}$/', $peer) === 1
            || str_starts_with($peer, '+')
            || stripos($peer, 'joinchat') === 0;
    }

    /** Публичная ссылка на пост источника, если у канала есть username. */
    public static function messageLink(string|int $peer, int $messageId): ?string
    {
        if (is_int($peer)) {
            return null;
        }
        $peer = self::normalize($peer);
        return is_string($peer) && preg_match('/^[A-Za-z][A-Za-z0-9_]{3,31}$/', $peer) === 1
            ? "https://t.me/{$peer}/{$messageId}"
            : null;
    }

    /** Как показывать в панели. */
    public static function label(string|int $peer): string
    {
        return is_int($peer) ? (string)$peer : '@' . $peer;
    }
}
