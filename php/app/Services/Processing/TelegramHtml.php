<?php

declare(strict_types=1);

namespace App\Services\Processing;

use danog\MadelineProto\StrTools;
use Throwable;

/**
 * Проверка HTML, который администратор вводит в подписи и правила:
 * Telegram понимает только свой узкий набор тегов, остальное молча ломает пост.
 */
final class TelegramHtml
{
    private const ALLOWED = ['b', 'strong', 'i', 'em', 'u', 'ins', 's', 'strike', 'del',
                             'a', 'code', 'pre', 'blockquote', 'tg-spoiler', 'span', 'br'];

    /** @return array<int, string> список проблем; пустой — всё в порядке */
    public static function problems(string $html): array
    {
        $problems = [];
        preg_match_all('#<\s*(/?)\s*([a-z][a-z0-9-]*)([^>]*)>#i', $html, $tags, PREG_SET_ORDER);
        foreach ($tags as [$whole, $closing, $name, $attributes]) {
            $name = strtolower($name);
            if (!in_array($name, self::ALLOWED, true)) {
                $problems[] = "Тег <{$name}> Telegram не поддерживает";
                continue;
            }
            if ($closing === '' && $name === 'a') {
                if (preg_match('#href\s*=\s*["\']([^"\']+)["\']#i', $attributes, $href) !== 1) {
                    $problems[] = 'У ссылки нет href';
                } elseif (preg_match('#^(https?://|tg://)#i', $href[1]) !== 1) {
                    $problems[] = 'Ссылка должна начинаться с https://, http:// или tg:// — ' . $href[1];
                }
            }
            if ($closing === '' && $name === 'span'
                && preg_match('#class\s*=\s*["\']tg-spoiler["\']#i', $attributes) !== 1) {
                $problems[] = 'Тег <span> допустим только как <span class="tg-spoiler">';
            }
        }
        if ($problems === []) {
            try {
                StrTools::htmlToMessageEntities(Html::normalize($html));
            } catch (Throwable $e) {
                $problems[] = 'Telegram не разберёт этот HTML: ' . $e->getMessage();
            }
        }
        return array_values(array_unique($problems));
    }

    /** Как текст будет выглядеть в канале (без разметки). */
    public static function plain(string $html): string
    {
        try {
            return StrTools::htmlToMessageEntities(Html::normalize($html))->message;
        } catch (Throwable) {
            return Html::visible(Html::normalize($html));
        }
    }
}
