<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Helpers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CsvEscapeTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function riskyCells(): array
    {
        return [
            'equals'          => ['=cmd|\' /C calc\'!A0', "'=cmd|' /C calc'!A0"],
            'plus'            => ['+1+1', "'+1+1"],
            'at'              => ['@SUM(1,1)', "'@SUM(1,1)"],
            'minus formula'   => ['-2+3+cmd', "'-2+3+cmd"],
            'tab then equals' => ["\t=1+1", "'\t=1+1"],
            'cr then equals'  => ["\r=1+1", "'\r=1+1"],
            'leading space'   => [' =1+1', "' =1+1"],
        ];
    }

    #[DataProvider('riskyCells')]
    public function testPrefixesRiskyCells(mixed $input, string $expected): void
    {
        self::assertSame($expected, vl_csv_escape($input));
    }

    public function testLeavesPlainValuesAndNumbersAlone(): void
    {
        self::assertSame('Widget', vl_csv_escape('Widget'));
        self::assertSame('-12.50', vl_csv_escape('-12.50'));
        self::assertSame('-5', vl_csv_escape(-5));
        self::assertSame('0', vl_csv_escape(0));
        self::assertSame('a=b', vl_csv_escape('a=b'));
        self::assertSame('', vl_csv_escape(''));
        self::assertSame('', vl_csv_escape(null));
    }
}
