<?php

declare(strict_types=1);

namespace Tests;

use App\Services\Processing\RuleEngine;
use App\Services\Queue\ClientRegistry;
use App\Services\Queue\Ingestor;
use danog\MadelineProto\StrTools;
use PHPUnit\Framework\TestCase;

final class IngestTest extends TestCase
{
    private function message(int $id, ?int $group = null): array
    {
        $message = ['_' => 'message', 'id' => $id, 'message' => 'пост ' . $id];
        if ($group !== null) {
            $message['grouped_id'] = $group;
        }
        return $message;
    }

    public function testIncompleteBatchIsTakenAsIs(): void
    {
        $messages = [$this->message(1), $this->message(2, 7), $this->message(3, 7)];
        self::assertSame($messages, Ingestor::holdBackTrailingAlbum($messages, 10));
    }

    public function testFullBatchEndingWithAlbumHoldsTheAlbumBack(): void
    {
        $messages = [$this->message(1), $this->message(2), $this->message(3, 9), $this->message(4, 9)];
        $kept = Ingestor::holdBackTrailingAlbum($messages, 4);
        self::assertSame([1, 2], array_column($kept, 'id'));   // альбом 3–4 заберём целиком в следующий раз
    }

    public function testFullBatchEndingWithPlainPostIsTakenAsIs(): void
    {
        $messages = [$this->message(1, 5), $this->message(2, 5), $this->message(3)];
        self::assertSame($messages, Ingestor::holdBackTrailingAlbum($messages, 3));
    }

    public function testBatchThatIsOneWholeAlbumIsNotHeldForever(): void
    {
        $messages = array_map(fn(int $id): array => $this->message($id, 42), range(1, 10));
        self::assertCount(10, Ingestor::holdBackTrailingAlbum($messages, 10));
    }

    public function testAlbumPartsAreGroupedInOrder(): void
    {
        $ingestor = new Ingestor(new ClientRegistry());
        $groups = $ingestor->groupAlbums([
            $this->message(1), $this->message(2, 8), $this->message(3, 8), $this->message(4, 8), $this->message(5),
        ]);
        self::assertSame([[1], [2, 3, 4], [5]], array_map(
            static fn(array $group): array => array_column($group, 'id'), $groups
        ));
    }

    /**
     * Смещения entities Telegram считает в UTF-16. Флаг 🇺🇿 — это 4 единицы UTF-16,
     * но 2 символа: ошибка в подсчёте сдвинула бы ссылки подписи, и очистка бы промахнулась.
     */
    public function testEmojiBeforeFooterDoesNotShiftLinks(): void
    {
        $text = "🇺🇿 Президент провёл совещание 👍\n\nPrezident.uz|Facebook|X";
        $utf16Offset = static fn(string $prefix): int => intdiv(strlen(mb_convert_encoding($prefix, 'UTF-16LE', 'UTF-8')), 2);

        $entities = [];
        $cursor = 0;
        foreach ([['Prezident.uz', 'https://president.uz/'], ['Facebook', 'https://facebook.com/Mirziyoyev'], ['X', 'https://x.com/president_uz']] as [$label, $url]) {
            $position = mb_strpos($text, $label, $cursor);
            $cursor = $position + mb_strlen($label);
            $entities[] = [
                '_'      => 'messageEntityTextUrl',
                'offset' => $utf16Offset(mb_substr($text, 0, $position)),
                'length' => $utf16Offset($label),
                'url'    => $url,
            ];
        }

        $html = StrTools::entitiesToHtml($text, $entities);
        self::assertStringContainsString('<a href="https://president.uz/">Prezident.uz</a>', $html,
            'ссылка должна охватывать ровно «Prezident.uz», иначе смещения посчитаны не в UTF-16');

        $rules = [['name' => 'Подпись', 'type' => 'SOCIAL_FOOTER', 'pattern' => null, 'replacement' => null,
                   'options' => [], 'priority' => 10, 'is_active' => 1]];
        $cleaned = (new RuleEngine($rules))->apply($html)->html;
        self::assertSame('🇺🇿 Президент провёл совещание 👍', $cleaned);
    }
}
