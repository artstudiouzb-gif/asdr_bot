<?php

declare(strict_types=1);

namespace App\Services\Processing;

/** Мелкие операции над HTML-представлением поста Telegram. */
final class Html
{
    public const ANCHOR = '#<a\s+href=["\']([^"\']*)["\'][^>]*>(.*?)</a>#is';
    public const TAG = '#<[^>]+>#';
    public const URL = '#(?:https?://|www\.)[^\s<>()\[\]"\']+#i';

    /** <br> и \r\n приводим к обычному переводу строки. */
    public static function normalize(string $html): string
    {
        $html = str_replace(["\r\n", "\r"], "\n", $html);
        return (string)preg_replace('#<br\s*/?>#i', "\n", $html);
    }

    /** Видимый текст строки: без тегов и HTML-сущностей. */
    public static function visible(string $html): string
    {
        return html_entity_decode((string)preg_replace(self::TAG, '', $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** @return array<int, string> ссылки строки: и из href, и написанные текстом */
    public static function urls(string $html): array
    {
        preg_match_all(self::ANCHOR, $html, $anchors);
        preg_match_all(self::URL, self::visible($html), $bare);
        return array_merge($anchors[1] ?? [], $bare[0] ?? []);
    }

    public static function host(string $url): string
    {
        $host = strtolower(trim($url));
        $host = (string)preg_replace('#^[a-z]+://#i', '', $host);
        $host = explode('/', $host)[0];
        $host = explode('?', $host)[0];
        $host = explode('#', $host)[0];
        $host = explode(':', (string)(explode('@', $host)[count(explode('@', $host)) - 1]))[0];
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /** Слова без цифр и знаков — для проверки «в строке остались только названия соцсетей». */
    public static function words(string $text): array
    {
        preg_match_all('/[^\W\d_]+/u', $text, $matches);
        return $matches[0] ?? [];
    }

    /** Убирает лишние пустые строки и пробелы по краям. */
    public static function tidy(string $html): string
    {
        $lines = array_map(static fn(string $line): string => rtrim($line), explode("\n", $html));
        $html = implode("\n", $lines);
        return trim((string)preg_replace("/\n{3,}/", "\n\n", $html));
    }
}
