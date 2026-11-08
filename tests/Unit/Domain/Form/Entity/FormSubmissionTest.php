<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Domain\Form\Entity;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Form\Entity\FormSubmission;

final class FormSubmissionTest extends TestCase
{
    public function testConstructor(): void
    {
        $sub = new FormSubmission(1, ['name' => 'John']);

        self::assertSame(1, $sub->getFormId());
        self::assertSame(['name' => 'John'], $sub->getData());
        self::assertNull($sub->getIpAddress());
        self::assertNull($sub->getUserAgent());
        self::assertFalse($sub->isRead());
    }

    public function testSetters(): void
    {
        $sub = new FormSubmission(1, []);

        $sub->setIpAddress('127.0.0.1');
        $sub->setUserAgent('Mozilla/5.0');
        $sub->markRead();

        self::assertSame('127.0.0.1', $sub->getIpAddress());
        self::assertSame('Mozilla/5.0', $sub->getUserAgent());
        self::assertTrue($sub->isRead());
    }

    public function testFromRow(): void
    {
        $sub = FormSubmission::fromRow([
            'id'          => '20',
            'form_id'     => '5',
            'data'        => '{"email":"a@b.com","msg":"Hello"}',
            'ip_address'  => '10.0.0.1',
            'user_agent'  => 'Chrome',
            'is_read'     => '1',
            'created_at'  => '2024-07-01 14:00:00',
        ]);

        self::assertSame(20, $sub->getId());
        self::assertSame(5, $sub->getFormId());
        self::assertSame(['email' => 'a@b.com', 'msg' => 'Hello'], $sub->getData());
        self::assertSame('10.0.0.1', $sub->getIpAddress());
        self::assertSame('Chrome', $sub->getUserAgent());
        self::assertTrue($sub->isRead());
        self::assertInstanceOf(\DateTimeImmutable::class, $sub->getCreatedAt());
    }

    public function testFromRowEmptyData(): void
    {
        $sub = FormSubmission::fromRow([
            'id'      => '1',
            'form_id' => '1',
            'data'    => null,
        ]);

        self::assertSame([], $sub->getData());
        self::assertNull($sub->getIpAddress());
        self::assertNull($sub->getUserAgent());
        self::assertFalse($sub->isRead());
    }
}
