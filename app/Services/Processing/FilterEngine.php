<?php

declare(strict_types=1);

namespace App\Services\Processing;

/** Решает, публиковать пост или пропустить. */
final class FilterEngine
{
    /** @param array<int, array<string, mixed>> $filters строки таблицы filters */
    public function __construct(private readonly array $filters)
    {
    }

    /** @return string|null причина пропуска или null, если пост проходит */
    public function reject(string $plainText, bool $hasMedia, bool $isForward): ?string
    {
        $includes = [];
        $includeMatched = false;

        foreach ($this->filters as $filter) {
            if ((int)($filter['is_active'] ?? 1) !== 1) {
                continue;
            }
            $value = (string)($filter['value'] ?? '');
            $insensitive = (int)($filter['case_insensitive'] ?? 1) === 1;

            switch ((string)$filter['kind']) {
                case 'include_keyword':
                    $includes[] = $value;
                    $includeMatched = $includeMatched || $this->contains($plainText, $value, $insensitive);
                    break;
                case 'include_regex':
                    $includes[] = $value;
                    $includeMatched = $includeMatched || $this->regex($plainText, $value, $insensitive);
                    break;
                case 'exclude_keyword':
                    if ($this->contains($plainText, $value, $insensitive)) {
                        return 'стоп-слово «' . $value . '»';
                    }
                    break;
                case 'exclude_regex':
                    if ($this->regex($plainText, $value, $insensitive)) {
                        return 'совпадение с исключающим шаблоном';
                    }
                    break;
                case 'min_length':
                    if (mb_strlen(trim($plainText)) < (int)$value) {
                        return 'текст короче ' . (int)$value . ' символов';
                    }
                    break;
                case 'max_length':
                    if ((int)$value > 0 && mb_strlen(trim($plainText)) > (int)$value) {
                        return 'текст длиннее ' . (int)$value . ' символов';
                    }
                    break;
                case 'require_media':
                    if (!$hasMedia) {
                        return 'нет медиа';
                    }
                    break;
                case 'skip_media':
                    if ($hasMedia) {
                        return 'пост с медиа';
                    }
                    break;
                case 'skip_forwards':
                    if ($isForward) {
                        return 'пересланный пост';
                    }
                    break;
            }
        }

        if ($includes !== [] && !$includeMatched) {
            return 'нет ни одного из обязательных слов';
        }
        return null;
    }

    private function contains(string $haystack, string $needle, bool $insensitive): bool
    {
        if ($needle === '') {
            return false;
        }
        return $insensitive
            ? mb_stripos($haystack, $needle) !== false
            : str_contains($haystack, $needle);
    }

    private function regex(string $subject, string $pattern, bool $insensitive): bool
    {
        if ($pattern === '') {
            return false;
        }
        $regex = '#' . str_replace('#', '\#', $pattern) . '#u' . ($insensitive ? 'i' : '');
        return @preg_match($regex, $subject) === 1;
    }
}
