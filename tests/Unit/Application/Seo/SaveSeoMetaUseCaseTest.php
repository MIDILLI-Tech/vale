<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Seo;

use PHPUnit\Framework\TestCase;
use ValeApp\Application\Seo\SaveSeoMetaUseCase;
use ValeApp\Domain\Seo\Entity\SeoMeta;
use ValeApp\Domain\Seo\Repository\SeoMetaRepositoryInterface;

final class SaveSeoMetaUseCaseTest extends TestCase
{
    private function buildUseCase(SeoMetaRepositoryInterface $repo): SaveSeoMetaUseCase
    {
        return new SaveSeoMetaUseCase($repo);
    }

    private function savingRepo(): SeoMetaRepositoryInterface
    {
        $repo = $this->createMock(SeoMetaRepositoryInterface::class);
        $repo->method('findByEntity')->willReturn(null);
        $repo->expects(self::once())->method('save')->willReturnCallback(
            static function (SeoMeta $seo): SeoMeta {
                return $seo;
            }
        );

        return $repo;
    }

    public function testFriendlyBooleansComposeRobotsDirective(): void
    {
        $useCase = $this->buildUseCase($this->savingRepo());

        $seo = $useCase->execute('post', 1, [
            'allow_index'  => '0',
            'allow_follow' => '0',
            'show_sitemap' => '0',
        ]);

        self::assertSame('noindex,nofollow', $seo->getRobots());
        self::assertTrue($seo->isSitemapExcluded());
    }

    public function testNofollowSurvivesWhenIndexStaysAllowed(): void
    {
        $useCase = $this->buildUseCase($this->savingRepo());

        $seo = $useCase->execute('page', 2, [
            'allow_index'  => '1',
            'allow_follow' => '0',
            'show_sitemap' => '1',
        ]);

        self::assertSame('index,nofollow', $seo->getRobots());
        self::assertFalse($seo->isSitemapExcluded());
    }

    public function testNoindexForcesSitemapExclusion(): void
    {
        $useCase = $this->buildUseCase($this->savingRepo());

        $seo = $useCase->execute('post', 3, [
            'allow_index'  => '0',
            'allow_follow' => '1',
            'show_sitemap' => '1',
        ]);

        self::assertSame('noindex,follow', $seo->getRobots());
        self::assertTrue($seo->isSitemapExcluded());
    }

    public function testLegacyRobotsStringStillHonored(): void
    {
        $useCase = $this->buildUseCase($this->savingRepo());

        $seo = $useCase->execute('product', 4, [
            'robots' => 'noindex,nofollow',
        ]);

        self::assertSame('noindex,nofollow', $seo->getRobots());
        self::assertTrue($seo->isSitemapExcluded());
    }

    public function testDefaultsToIndexFollowAndIncluded(): void
    {
        $useCase = $this->buildUseCase($this->savingRepo());

        $seo = $useCase->execute('post', 5, []);

        self::assertSame('index,follow', $seo->getRobots());
        self::assertFalse($seo->isSitemapExcluded());
    }
}
