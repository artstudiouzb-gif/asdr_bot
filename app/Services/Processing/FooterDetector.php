<?php

declare(strict_types=1);

namespace App\Services\Processing;

/**
 * Распознаёт блок-подпись источника:
 *
 *     Prezident.uz|Facebook|Instagram|YouTube|X
 *
 * Строка удаляется, если в ней есть ссылка на «социальный» домен и при этом
 * из осмысленного текста в ней остались только названия соцсетей. Поэтому
 * «Трансляция идёт на канале в прямом эфире» со ссылкой на YouTube остаётся:
 * в ней есть обычные слова.
 */
final class FooterDetector
{
    public const DEFAULT_DOMAINS = [
        'president.uz', 'prezident.uz', 'facebook.com', 'fb.com', 'fb.me',
        'instagram.com', 'youtube.com', 'youtu.be', 'x.com', 'twitter.com',
        'threads.net', 'threads.com', 'tiktok.com', 'vk.com', 'ok.ru',
        'linkedin.com', 'dzen.ru', 'rutube.ru', 't.me', 'telegram.me',
    ];

    public const DEFAULT_LABELS = [
        'prezident', 'president', 'prezidenti', 'prezidentuz',
        'facebook', 'fb', 'instagram', 'insta', 'ig', 'youtube', 'yt',
        'x', 'twitter', 'telegram', 'tg', 'tiktok', 'threads', 'vk',
        'ok', 'odnoklassniki', 'linkedin', 'dzen', 'rutube', 'web', 'sayt', 'sayti',
        'uz', 'com', 'ru', 'net', 'org', 'me', 'info', 'www',
    ];

    /** @var array<int, string> */
    private array $domains;
    /** @var array<int, string> */
    private array $labels;

    public function __construct(?array $domains = null, ?array $labels = null)
    {
        $this->domains = array_map(
            static fn(string $d): string => ltrim(strtolower(trim($d)), '.'),
            $domains === null || $domains === [] ? self::DEFAULT_DOMAINS : $domains
        );
        $this->labels = array_map(
            static fn(string $l): string => mb_strtolower(trim($l)),
            $labels === null || $labels === [] ? self::DEFAULT_LABELS : $labels
        );
    }

    public function isBlockedUrl(string $url): bool
    {
        $host = Html::host($url);
        if ($host === '') {
            return false;
        }
        foreach ($this->domains as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }
        return false;
    }

    /** Остались ли в тексте только названия соцсетей. */
    public function isLabelsOnly(string $text): bool
    {
        foreach (Html::words($text) as $word) {
            if (!in_array(mb_strtolower($word), $this->labels, true)) {
                return false;
            }
        }
        return true;
    }

    public function isFooterLine(string $line): bool
    {
        $line = trim($line);
        if ($line === '') {
            return false;
        }
        $urls = Html::urls($line);
        $visible = Html::visible($line);
        $residual = (string)preg_replace(Html::URL, ' ', $visible);

        $blocked = array_filter($urls, fn(string $url): bool => $this->isBlockedUrl($url));
        if ($blocked !== [] && $this->isLabelsOnly($residual)) {
            return true;
        }
        // та же подпись, но ссылки потерялись при пересылке: «Prezident.uz | Facebook | X»
        return $urls === []
            && str_contains($visible, '|')
            && count(Html::words($residual)) >= 2
            && $this->isLabelsOnly($residual);
    }

    /** Снимает ссылку с «социальных» доменов, оставляя текст. */
    public function unwrapBlockedLinks(string $html): string
    {
        return (string)preg_replace_callback(Html::ANCHOR, function (array $match): string {
            return $this->isBlockedUrl($match[1]) ? $match[2] : $match[0];
        }, $html);
    }
}
