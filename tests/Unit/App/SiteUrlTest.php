<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Shared\ValueObject\Slug;

use function site_url;

final class SiteUrlTest extends TestCase
{
    public function testStringableQueryParamRendersFlat(): void
    {
        self::assertSame(
            '/admin/posts/create?content_type=testa',
            site_url('/admin/posts/create', [], ['content_type' => new Slug('testa')]),
        );
    }

    public function testStringQueryParamUnchanged(): void
    {
        self::assertSame(
            '/admin/posts/create?content_type=testa',
            site_url('/admin/posts/create', [], ['content_type' => 'testa']),
        );
    }
}
