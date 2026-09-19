<?php

declare(strict_types=1);

namespace App\Services\Processing;

/**
 * Применяет правила очистки к HTML-представлению поста.
 *
 * Порядок типов фиксирован (сначала снимаем блоки, потом точечные замены),
 * внутри типа — по полю priority. Так правила администратора предсказуемы.
 */
final class RuleEngine
{
    private const TYPE_ORDER = [
        'DROP_MESSAGE'   => 10,
        'SOCIAL_FOOTER'  => 20,
        'REMOVE_BLOCK'   => 30,
        'REMOVE_LINE'    => 40,
        'REMOVE_URL'     => 50,
        'REGEX_REPLACE'  => 60,
        'REPLACE_TEXT'   => 70,
        'PREPEND_TEXT'   => 80,
        'APPEND_TEXT'    => 90,
    ];

    /** @param array<int, array<string, mixed>> $rules строки таблицы rules */
    public function __construct(private readonly array $rules)
    {
    }

    public function apply(string $html): RuleResult
    {
        $html = Html::normalize($html);
        $applied = [];

        foreach ($this->sortedRules() as $rule) {
            $name = (string)$rule['name'];
            $type = (string)$rule['type'];
            $pattern = (string)($rule['pattern'] ?? '');
            $replacement = (string)($rule['replacement'] ?? '');
            $options = is_array($rule['options'] ?? null)
                ? $rule['options']
                : (json_decode((string)($rule['options'] ?? '{}'), true) ?: []);

            $before = $html;

            switch ($type) {
                case 'DROP_MESSAGE':
                    if ($pattern !== '' && $this->matches($pattern, Html::visible($html), $options)) {
                        return new RuleResult('', [...$applied, $name], true, 'правило «' . $name . '»');
                    }
                    break;

                case 'SOCIAL_FOOTER':
                    $html = $this->applySocialFooter($html, $options);
                    break;

                case 'REMOVE_BLOCK':
                    $html = $this->replace($pattern, '', $html, $options + ['multiline' => true]);
                    break;

                case 'REMOVE_LINE':
                    $html = $this->removeLines($html, $pattern, $options);
                    break;

                case 'REMOVE_URL':
                    $html = $this->removeUrls($html, $pattern);
                    break;

                case 'REGEX_REPLACE':
                    $html = $this->replace($pattern, $replacement, $html, $options);
                    break;

                case 'REPLACE_TEXT':
                    if ($pattern !== '') {
                        $html = str_replace($pattern, $replacement, $html);
                    }
                    break;

                case 'PREPEND_TEXT':
                    $html = $replacement === '' ? $html : $replacement . "\n\n" . $html;
                    break;

                case 'APPEND_TEXT':
                    $html = $replacement === '' ? $html : $html . "\n\n" . $replacement;
                    break;
            }

            if ($html !== $before) {
                $applied[] = $name;
            }
        }

        return new RuleResult(Html::tidy($html), $applied);
    }

    /** @return array<int, array<string, mixed>> */
    private function sortedRules(): array
    {
        $rules = array_values(array_filter($this->rules, static fn(array $r): bool => (int)($r['is_active'] ?? 1) === 1));
        usort($rules, static function (array $a, array $b): int {
            $weight = (self::TYPE_ORDER[(string)$a['type']] ?? 100) <=> (self::TYPE_ORDER[(string)$b['type']] ?? 100);
            return $weight !== 0 ? $weight : ((int)($a['priority'] ?? 100) <=> (int)($b['priority'] ?? 100));
        });
        return $rules;
    }

    private function applySocialFooter(string $html, array $options): string
    {
        $detector = new FooterDetector(
            is_array($options['domains'] ?? null) ? $options['domains'] : null,
            is_array($options['labels'] ?? null) ? $options['labels'] : null,
        );

        $kept = [];
        foreach (explode("\n", $html) as $line) {
            if (!$detector->isFooterLine($line)) {
                $kept[] = $line;
            }
        }
        $html = implode("\n", $kept);

        if (($options['unwrap_links'] ?? true) !== false) {
            $html = $detector->unwrapBlockedLinks($html);
        }
        return $html;
    }

    private function removeLines(string $html, string $pattern, array $options): string
    {
        if ($pattern === '') {
            return $html;
        }
        $kept = [];
        foreach (explode("\n", $html) as $line) {
            if (trim($line) !== '' && $this->matches($pattern, Html::visible($line), $options)) {
                continue;
            }
            $kept[] = $line;
        }
        return implode("\n", $kept);
    }

    /** Пустой шаблон — снять все ссылки; иначе только совпадающие по домену или regex. */
    private function removeUrls(string $html, string $pattern): string
    {
        $html = (string)preg_replace_callback(Html::ANCHOR, static function (array $match) use ($pattern): string {
            if ($pattern === '' || stripos($match[1], $pattern) !== false || @preg_match('#' . $pattern . '#i', $match[1]) === 1) {
                return $match[2];
            }
            return $match[0];
        }, $html);

        return (string)preg_replace_callback(Html::URL, static function (array $match) use ($pattern): string {
            if ($pattern === '' || stripos($match[0], $pattern) !== false || @preg_match('#' . $pattern . '#i', $match[0]) === 1) {
                return '';
            }
            return $match[0];
        }, $html);
    }

    private function replace(string $pattern, string $replacement, string $subject, array $options): string
    {
        $regex = $this->compile($pattern, $options);
        if ($regex === null) {
            return $subject;
        }
        $result = @preg_replace($regex, $replacement, $subject);
        return is_string($result) ? $result : $subject;
    }

    private function matches(string $pattern, string $subject, array $options): bool
    {
        $regex = $this->compile($pattern, $options);
        if ($regex === null) {
            return false;
        }
        return @preg_match($regex, $subject) === 1;
    }

    /** Шаблон администратора → безопасное регулярное выражение. */
    private function compile(string $pattern, array $options): ?string
    {
        if ($pattern === '') {
            return null;
        }
        $flags = 'u';
        if (($options['case_insensitive'] ?? true) !== false) {
            $flags .= 'i';
        }
        if (($options['multiline'] ?? false) === true) {
            $flags .= 'ms';
        }
        $regex = '#' . str_replace('#', '\#', $pattern) . '#' . $flags;
        return @preg_match($regex, '') === false ? null : $regex;
    }

    /** Проверка шаблона при сохранении в панели. */
    public static function isValidPattern(string $pattern): bool
    {
        return $pattern === '' || @preg_match('#' . str_replace('#', '\#', $pattern) . '#u', '') !== false;
    }
}
