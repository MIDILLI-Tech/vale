<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Content;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use ValeApp\Application\Content\SaveTagUseCase;
use ValeApp\Domain\Content\Entity\Tag;
use ValeApp\Domain\Content\Repository\TagRepositoryInterface;
use ValeApp\Domain\Shared\Exception\ValidationException;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Infrastructure\Content\RedirectService;
use ValeApp\Infrastructure\Content\SlugService;

final class SaveTagUseCaseTest extends TestCase
{
    private $repository;
    private $slugService;
    private $redirectService;
    private $useCase;
    private $db;

    protected function setUp(): void
    {
        $this->repository      = $this->createStub(TagRepositoryInterface::class);
        $this->db              = $this->createStub(Connection::class);
        $this->slugService     = new SlugService($this->db);
        $this->redirectService = $this->createStub(RedirectService::class);
        $this->useCase         = $this->buildUseCase();
    }

    private function buildUseCase(): SaveTagUseCase
    {
        return new SaveTagUseCase($this->repository, $this->slugService, $this->redirectService);
    }

    public function testExecuteCreatesNewTag(): void
    {
        $data = ['name' => 'New Tag'];

        $this->db->method('fetchOne')->willReturn(0);

        $this->repository = $this->createMock(TagRepositoryInterface::class);
        $this->useCase    = $this->buildUseCase();

        $this->repository->expects(self::once())
            ->method('save')
            ->with(self::callback(function ($tag) {
                return $tag instanceof \ValeApp\Domain\Content\Entity\Tag
                    && $tag->getName()        === 'New Tag'
                    && $tag->getSlug()->value === 'new-tag';
            }))
            ->willReturnArgument(0);

        $result = $this->useCase->execute($data);

        self::assertInstanceOf(Tag::class, $result);
        self::assertSame('New Tag', $result->getName());
        self::assertSame('new-tag', $result->getSlug()->value);
    }

    public function testExecuteUpdatesExistingTag(): void
    {
        $tag = new Tag('Old Name', new Slug('old-slug'));
        $tag->setId(123);

        $this->repository = $this->createMock(TagRepositoryInterface::class);
        $this->useCase    = $this->buildUseCase();

        $this->repository->expects(self::once())
            ->method('findById')
            ->with(123)
            ->willReturn($tag);

        $this->db->method('fetchOne')->willReturn(0);

        $this->repository->expects(self::once())
            ->method('save')
            ->willReturnArgument(0);

        $result = $this->useCase->execute(['name' => 'Updated Name'], 123);

        self::assertSame('Updated Name', $result->getName());
        self::assertSame('updated-name', $result->getSlug()->value);
    }

    public function testExecuteThrowsExceptionIfNameMissing(): void
    {
        $this->expectException(ValidationException::class);
        $this->useCase->execute(['name' => '']);
    }

    public function testExecuteThrowsExceptionIfTagNotFound(): void
    {
        $this->repository->method('findById')->willReturn(null);

        $this->expectException(ValidationException::class);
        $this->useCase->execute(['name' => 'Name'], 999);
    }
}
