<?php

declare(strict_types=1);

namespace Tests;

use App\Services\Telegram\Peer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PeerTest extends TestCase
{
    #[DataProvider('identifiers')]
    public function testNormalize(string $input, string|int $expected): void
    {
        self::assertSame($expected, Peer::normalize($input));
    }

    public static function identifiers(): array
    {
        return [
            'username'         => ['@shmirziyoyev', 'shmirziyoyev'],
            'без собаки'       => ['shmirziyoyev', 'shmirziyoyev'],
            'ссылка'           => ['https://t.me/shmirziyoyev', 'shmirziyoyev'],
            'ссылка на пост'   => ['https://t.me/shmirziyoyev/35508', 'shmirziyoyev'],
            'превью-страница'  => ['https://t.me/s/shmirziyoyev', 'shmirziyoyev'],
            'без схемы'        => ['t.me/asr_uz', 'asr_uz'],
            'числовой id'      => ['-1001234567890', -1001234567890],
            'пробелы'          => ['  @asr_uz  ', 'asr_uz'],
            'приглашение'      => ['https://t.me/+AbCdEf', '+AbCdEf'],
        ];
    }

    public function testValidation(): void
    {
        self::assertTrue(Peer::isValid('@shmirziyoyev'));
        self::assertTrue(Peer::isValid('-1001234567890'));
        self::assertTrue(Peer::isValid('https://t.me/asr_uz'));
        self::assertFalse(Peer::isValid(''));
        self::assertFalse(Peer::isValid('@ab'));            // слишком короткий username
        self::assertFalse(Peer::isValid('не канал!'));
    }

    public function testMessageLink(): void
    {
        self::assertSame('https://t.me/shmirziyoyev/35508', Peer::messageLink('shmirziyoyev', 35508));
        self::assertNull(Peer::messageLink(-1001234567890, 5));
    }

    public function testLabel(): void
    {
        self::assertSame('@asr_uz', Peer::label('asr_uz'));
        self::assertSame('-100123', Peer::label(-100123));
    }
}
