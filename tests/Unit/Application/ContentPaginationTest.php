<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use ValeApp\Application\Form\FormAccessService;
use ValeApp\Application\Form\FormCaptchaService;
use ValeApp\Domain\Content\Entity\Post;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Form\Repository\FormRepositoryInterface;
use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Infrastructure\Content\ShortcodeProcessor;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Security\CsrfManager;
use ValeApp\Infrastructure\Session\SessionManager;

final class ContentPaginationTest extends TestCase
{
    public function testMultiPageTocGeneratesPageUrlsForOtherPages(): void
    {
        $forms    = $this->createStub(FormRepositoryInterface::class);
        $csrf     = $this->createStub(CsrfManager::class);
        $session  = $this->createStub(SessionManager::class);
        $posts    = $this->createStub(PostRepositoryInterface::class);
        $media    = $this->createStub(MediaRepositoryInterface::class);
        $products = $this->createStub(ProductRepositoryInterface::class);
        $settings = $this->createStub(DbalSettingsRepository::class);

        $formAccess = new FormAccessService(
            $session,
            $this->createStub(SubscriberRepositoryInterface::class),
            $settings,
        );

        $formCaptcha = new FormCaptchaService(
            $this->createStub(FormRepositoryInterface::class),
            $settings,
        );

        $processor = new ShortcodeProcessor($forms, $csrf, $session, $posts, $media, $products, $settings, $formAccess, $formCaptcha);

        $fullBody = '<h2>Section One</h2><p>Intro text</p>[toc]'
            . '<!--nextpage title="Advanced Details"-->'
            . '<h2>Section Two</h2><p>Page 2 text</p>'
            . '<!--nextpage-->'
            . '<h3>Section Three</h3><p>Page 3 text</p>';

        $post = new Post('Test Post', new Slug('test-post'), 1);
        $post->setBody($fullBody);

        // Page 1 content segment
        $page1Segment = '<h2>Section One</h2><p>Intro text</p>[toc]';
        $post->setBody($page1Segment);
        $post->setMetaValue('_full_body', $fullBody);

        $_GET['page'] = 1;
        $outputPage1  = $processor->process($page1Segment, $post);

        // Section One should link to #section-one
        $this->assertStringContainsString('href="#section-one"', $outputPage1);
        // Section Two (on page 2) should link to ?page=2#section-two
        $this->assertStringContainsString('href="?page=2#section-two"', $outputPage1);
        // Section Three (on page 3) should link to ?page=3#section-three
        $this->assertStringContainsString('href="?page=3#section-three"', $outputPage1);
    }
}
