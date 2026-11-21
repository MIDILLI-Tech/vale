<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure\Admin;

use PHPUnit\Framework\TestCase;
use ValeApp\Infrastructure\Admin\IconHelper;

final class IconHelperTest extends TestCase
{
    public function testSystemContentTypesResolveToTheirNavigationIcons(): void
    {
        $default = IconHelper::resolveIcon('document-text');

        self::assertStringStartsWith('M19 20H5', IconHelper::resolveIcon('post'));
        self::assertStringStartsWith('M9 12h6', IconHelper::resolveIcon('page'));
        self::assertNotSame($default, IconHelper::resolveIcon('post'));
        self::assertNotSame($default, IconHelper::resolveIcon('page'));
    }
}
