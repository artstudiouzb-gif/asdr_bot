<?php

declare(strict_types=1);

namespace Tests;

use App\Services\Processing\TelegramHtml;
use App\Services\Processing\TextPipeline;
use PHPUnit\Framework\TestCase;

final class TelegramHtmlTest extends TestCase
{
    public function testAcceptsTelegramTags(): void
    {
        $html = '<a href="https://asr.gov.uz/">website</a> | <b>жирный</b> <tg-spoiler>тайна</tg-spoiler>';
        self::assertSame([], TelegramHtml::problems($html));
        self::assertSame('website | жирный тайна', TelegramHtml::plain($html));
    }

    public function testRejectsUnsupportedTagsAndLinks(): void
    {
        self::assertNotEmpty(TelegramHtml::problems('<div>блок</div>'));
        self::assertNotEmpty(TelegramHtml::problems('<a href="javascript:alert(1)">x</a>'));
        self::assertNotEmpty(TelegramHtml::problems('<a>без адреса</a>'));
        self::assertNotEmpty(TelegramHtml::problems('<span style="color:red">x</span>'));
    }

    public function testRouteWithoutSignature(): void
    {
        $pipeline = new TextPipeline([], [], null);
        self::assertSame('Новость', $pipeline->process('Новость')['html']);
    }

    public function testSignatureWithSeparatorLine(): void
    {
        $pipeline = new TextPipeline([], [], [
            'is_active' => 1, 'content' => 'ASR', 'position' => 'append', 'separator' => "\n\n———\n\n",
        ]);
        self::assertSame("Новость\n\n———\n\nASR", $pipeline->process('Новость')['html']);
    }
}
