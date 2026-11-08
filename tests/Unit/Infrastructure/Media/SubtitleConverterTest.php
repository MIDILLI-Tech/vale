<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure\Media;

use PHPUnit\Framework\TestCase;
use ValeApp\Infrastructure\Media\SubtitleConverter;

final class SubtitleConverterTest extends TestCase
{
    private const SRT = "1\n00:00:01,000 --> 00:00:04,000\nHello world\n\n"
        . "2\n00:00:05,500 --> 00:00:07,000\nSecond line\nstill second\n";

    public function testConvertsCommasToDotsAndPrependsHeader(): void
    {
        $vtt = SubtitleConverter::convertSrtToVtt(self::SRT);

        self::assertNotNull($vtt);
        self::assertStringStartsWith("WEBVTT\n\n", $vtt);
        self::assertStringContainsString('00:00:01.000 --> 00:00:04.000', $vtt);
        self::assertStringContainsString('00:00:05.500 --> 00:00:07.000', $vtt);
        self::assertStringNotContainsString(',', explode("\n", $vtt)[2] ?? '');
        self::assertStringContainsString('Hello world', $vtt);
    }

    public function testStripsNumericCueIdentifiers(): void
    {
        $vtt = SubtitleConverter::convertSrtToVtt(self::SRT);

        self::assertNotNull($vtt);
        self::assertStringNotContainsString("\n1\n", $vtt);
        self::assertStringNotContainsString("\n2\n", $vtt);
    }

    public function testHandlesCrlfAndBom(): void
    {
        $srt = "\xEF\xBB\xBF1\r\n00:00:01,000 --> 00:00:02,000\r\nHi\r\n";

        $vtt = SubtitleConverter::convertSrtToVtt($srt);

        self::assertNotNull($vtt);
        self::assertStringStartsWith("WEBVTT\n\n", $vtt);
        self::assertStringContainsString('00:00:01.000 --> 00:00:02.000', $vtt);
    }

    public function testReturnsNullForGarbage(): void
    {
        self::assertNull(SubtitleConverter::convertSrtToVtt(''));
        self::assertNull(SubtitleConverter::convertSrtToVtt("just some text\nno timing here"));
        self::assertNull(SubtitleConverter::convertSrtToVtt("1\nnot a timestamp\ntext"));
    }

    public function testSkipsBadCuesButKeepsGoodOnes(): void
    {
        $srt = "1\n00:00:01,000 --> 00:00:02,000\nGood\n\nBAD BLOCK\n\n"
            . "2\n00:00:03,000 --> 00:00:04,000\nAlso good\n";

        $vtt = SubtitleConverter::convertSrtToVtt($srt);

        self::assertNotNull($vtt);
        self::assertStringContainsString('Good', $vtt);
        self::assertStringContainsString('Also good', $vtt);
        self::assertStringNotContainsString('BAD BLOCK', $vtt);
    }
}
