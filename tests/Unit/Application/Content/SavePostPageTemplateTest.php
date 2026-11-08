<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Content;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use ValeApp\Application\Content\SavePostUseCase;
use ValeApp\Domain\Content\Entity\Post;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Content\Repository\RevisionRepositoryInterface;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Infrastructure\Content\RedirectService;
use ValeApp\Infrastructure\Content\SlugService;

final class SavePostPageTemplateTest extends TestCase
{
    private function buildUseCase(PostRepositoryInterface $posts): SavePostUseCase
    {
        $db = $this->createStub(Connection::class);
        $db->method('fetchOne')->willReturn(0);

        return new SavePostUseCase(
            $posts,
            new SlugService($db),
            $this->createStub(RevisionRepositoryInterface::class),
            $this->createStub(SettingsRepositoryInterface::class),
            $this->createStub(RedirectService::class),
            $this->createStub(ContentTypeRepositoryInterface::class),
        );
    }

    private function executeWithTemplate(string $template): Post
    {
        $posts = $this->createMock(PostRepositoryInterface::class);
        $posts->method('findById')->willReturn(null);
        $posts->expects(self::once())->method('save')->willReturnCallback(
            static function (Post $post): Post {
                $post->setId(7);
                return $post;
            }
        );

        return $this->buildUseCase($posts)->execute([
            'title'         => 'Template guard',
            'content_type'  => 'page',
            'author_id'     => 1,
            'status'        => 'draft',
            'page_template' => $template,
        ]);
    }

    public function testOverLongTemplateFallsBackToStandard(): void
    {
        $result = $this->executeWithTemplate(str_repeat('a', Post::MAX_PAGE_TEMPLATE_LENGTH + 1));

        self::assertSame('standard', $result->getPageTemplate());
    }

    public function testMaxLengthTemplateIsKept(): void
    {
        $template = str_repeat('b', Post::MAX_PAGE_TEMPLATE_LENGTH);

        $result = $this->executeWithTemplate($template);

        self::assertSame($template, $result->getPageTemplate());
    }

    public function testShortTemplateIsKept(): void
    {
        $result = $this->executeWithTemplate('about-midilli-controllers');

        self::assertSame('about-midilli-controllers', $result->getPageTemplate());
    }
}
