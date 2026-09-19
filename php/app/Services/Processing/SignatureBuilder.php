<?php

declare(strict_types=1);

namespace App\Services\Processing;

/** Своя подпись вместо вырезанной подписи источника. */
final class SignatureBuilder
{
    public static function apply(string $html, ?array $signature): string
    {
        if ($signature === null || (int)($signature['is_active'] ?? 1) !== 1) {
            return $html;
        }
        $content = trim((string)($signature['content'] ?? ''));
        if ($content === '') {
            return $html;
        }
        $separator = str_replace('\n', "\n", (string)($signature['separator'] ?? "\n\n"));
        if ($html === '') {
            return $content;
        }
        return ($signature['position'] ?? 'append') === 'prepend'
            ? $content . $separator . $html
            : $html . $separator . $content;
    }
}
