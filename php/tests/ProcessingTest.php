<?php

declare(strict_types=1);

namespace Tests;

use App\Services\Processing\FilterEngine;
use App\Services\Processing\RuleEngine;
use App\Services\Processing\SignatureBuilder;
use danog\MadelineProto\StrTools;
use PHPUnit\Framework\TestCase;

final class ProcessingTest extends TestCase
{
    private const FOOTER_HTML =
        '<a href="https://president.uz/">Prezident.uz</a>|'
        . '<a href="https://www.facebook.com/Mirziyoyev/">Facebook</a>|'
        . '<a href="https://www.instagram.com/mirziyoyev_sh/">Instagram</a>|'
        . '<a href="https://www.youtube.com/channel/UC61Jnumjuz8NXhSuLoZD2xg">YouTube</a>|'
        . '<a href="https://x.com/president_uz">X</a>';

    private const SIGNATURE = [
        'name' => 'ASR', 'is_active' => 1, 'position' => 'append', 'separator' => '\n\n',
        'content' => '<a href="https://asr.gov.uz/">website</a> | <a href="https://www.facebook.com/ASRUzb">facebook</a>',
    ];

    /** Правила «по умолчанию» — те же, что ставит миграция 0002. */
    private function defaultRules(): array
    {
        return [
            ['name' => 'Подпись источника', 'type' => 'SOCIAL_FOOTER', 'pattern' => null,
             'replacement' => null, 'options' => [], 'priority' => 10, 'is_active' => 1],
            ['name' => 'Призыв подписаться', 'type' => 'REMOVE_LINE', 'priority' => 20, 'is_active' => 1,
             'pattern' => '(подпис|подпиш|obuna|subscribe|наш\s+канал)', 'replacement' => null, 'options' => []],
        ];
    }

    public function testRemovesAnchorFooter(): void
    {
        $post = "Президент принял участие в церемонии.\n\n" . self::FOOTER_HTML;
        $result = (new RuleEngine($this->defaultRules()))->apply($post);
        self::assertSame('Президент принял участие в церемонии.', $result->html);
    }

    public function testRemovesPlainTextFooter(): void
    {
        $post = "Новость дня.\n\nPrezident.uz (https://president.uz/)|Facebook (https://www.facebook.com/Mirziyoyev/)|"
            . 'X (https://x.com/president_uz)';
        self::assertSame('Новость дня.', (new RuleEngine($this->defaultRules()))->apply($post)->html);
    }

    public function testRemovesFooterSplitAcrossLines(): void
    {
        $post = "Текст поста.\n<a href=\"https://president.uz/\">Prezident.uz</a>\n<a href=\"https://x.com/president_uz\">X</a>";
        self::assertSame('Текст поста.', (new RuleEngine($this->defaultRules()))->apply($post)->html);
    }

    public function testKeepsLinksToOtherSites(): void
    {
        $post = 'Подробности в <a href="https://gov.uz/news/1">постановлении</a>.';
        self::assertSame($post, (new RuleEngine($this->defaultRules()))->apply($post)->html);
    }

    public function testKeepsSentenceThatMentionsSocialLink(): void
    {
        $post = 'Трансляция идёт на <a href="https://youtube.com/live">канале</a> в прямом эфире.';
        $html = (new RuleEngine($this->defaultRules()))->apply($post)->html;
        self::assertSame('Трансляция идёт на канале в прямом эфире.', $html);   // ссылка снята, текст цел
    }

    public function testRemovesSubscribeLine(): void
    {
        $post = "Новость.\n\nПодписывайтесь на наш канал @somechannel";
        self::assertSame('Новость.', (new RuleEngine($this->defaultRules()))->apply($post)->html);
    }

    public function testDropMessageRule(): void
    {
        $rules = [['name' => 'Реклама', 'type' => 'DROP_MESSAGE', 'pattern' => 'реклама',
                   'replacement' => null, 'options' => [], 'priority' => 10, 'is_active' => 1]];
        $result = (new RuleEngine($rules))->apply('Это реклама, публиковать не нужно');
        self::assertTrue($result->dropped);
        self::assertStringContainsString('Реклама', (string)$result->dropReason);
    }

    public function testRegexReplaceAndRemoveLine(): void
    {
        $rules = [
            ['name' => 'Убрать метку', 'type' => 'REGEX_REPLACE', 'pattern' => '\s*erid:\s*\S+',
             'replacement' => '', 'options' => [], 'priority' => 10, 'is_active' => 1],
            ['name' => 'Фотоподпись', 'type' => 'REMOVE_LINE', 'pattern' => '^Фото:',
             'replacement' => null, 'options' => [], 'priority' => 10, 'is_active' => 1],
        ];
        $html = (new RuleEngine($rules))->apply("Новость erid: 12345\nФото: пресс-служба")->html;
        self::assertSame('Новость', $html);
    }

    public function testInactiveRuleIsIgnored(): void
    {
        $rules = $this->defaultRules();
        $rules[0]['is_active'] = 0;
        $html = (new RuleEngine($rules))->apply("Текст\n" . self::FOOTER_HTML)->html;
        self::assertStringContainsString('president.uz', $html);
    }

    public function testSignatureAppended(): void
    {
        $html = SignatureBuilder::apply('Новость.', self::SIGNATURE);
        self::assertSame("Новость.\n\n" . self::SIGNATURE['content'], $html);
    }

    public function testSignatureSkippedWhenInactive(): void
    {
        self::assertSame('Новость.', SignatureBuilder::apply('Новость.', ['is_active' => 0, 'content' => 'x']));
    }

    /** Полный круг: entities Telegram → HTML → очистка → обратно в entities. */
    public function testFullRoundTripWithRealPost(): void
    {
        $text = "Президент принял участие в церемонии.\n\nPrezident.uz|Facebook|Instagram|YouTube|X";
        $labels = [
            ['Prezident.uz', 'https://president.uz/'],
            ['Facebook', 'https://www.facebook.com/Mirziyoyev/'],
            ['Instagram', 'https://www.instagram.com/mirziyoyev_sh/'],
            ['YouTube', 'https://www.youtube.com/channel/UC61Jnumjuz8NXhSuLoZD2xg'],
            ['X', 'https://x.com/president_uz'],
        ];
        $entities = [];
        $position = 0;
        foreach ($labels as [$label, $url]) {
            $offset = mb_strpos($text, $label, $position);
            $position = $offset + mb_strlen($label);
            $entities[] = ['_' => 'messageEntityTextUrl', 'offset' => $offset,
                           'length' => mb_strlen($label), 'url' => $url];
        }

        $html = StrTools::entitiesToHtml($text, $entities);
        $cleaned = (new RuleEngine($this->defaultRules()))->apply($html);
        $withSignature = SignatureBuilder::apply($cleaned->html, self::SIGNATURE);
        $parsed = StrTools::htmlToMessageEntities($withSignature);

        self::assertSame("Президент принял участие в церемонии.\n\nwebsite | facebook", $parsed->message);
        self::assertCount(2, $parsed->entities);          // две ссылки нашей подписи
        self::assertStringNotContainsString('president.uz', $withSignature);
    }

    public function testFilters(): void
    {
        $filters = [
            ['kind' => 'exclude_keyword', 'value' => 'реклама', 'case_insensitive' => 1, 'is_active' => 1],
            ['kind' => 'min_length', 'value' => '20', 'case_insensitive' => 1, 'is_active' => 1],
        ];
        $engine = new FilterEngine($filters);
        self::assertNull($engine->reject('Достаточно длинный текст новости', false, false));
        self::assertStringContainsString('стоп-слово', (string)$engine->reject('Тут Реклама и ещё много слов', false, false));
        self::assertStringContainsString('короче', (string)$engine->reject('Коротко', false, false));
    }

    public function testIncludeKeywordsRequireAtLeastOne(): void
    {
        $engine = new FilterEngine([
            ['kind' => 'include_keyword', 'value' => 'экономика', 'case_insensitive' => 1, 'is_active' => 1],
            ['kind' => 'include_keyword', 'value' => 'инвестиции', 'case_insensitive' => 1, 'is_active' => 1],
        ]);
        self::assertNull($engine->reject('Новость про инвестиции в регион', false, false));
        self::assertNotNull($engine->reject('Новость про спорт', false, false));
    }

    public function testSkipForwardsAndMedia(): void
    {
        self::assertSame('пересланный пост',
            (new FilterEngine([['kind' => 'skip_forwards', 'value' => null, 'is_active' => 1]]))->reject('текст', false, true));
        self::assertSame('нет медиа',
            (new FilterEngine([['kind' => 'require_media', 'value' => null, 'is_active' => 1]]))->reject('текст', false, false));
    }
}
