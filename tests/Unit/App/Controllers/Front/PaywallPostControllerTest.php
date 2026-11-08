<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Controllers\Front;

use PHPUnit\Framework\TestCase;

final class PaywallPostControllerTest extends TestCase
{
    public function testPaywallNoDuplicateExcerptAndNoComments(): void
    {
        $postControllerContent = file_get_contents(__DIR__ . '/../../../../../app/Controllers/Front/PostController.php');
        $postTwigContent       = file_get_contents(__DIR__ . '/../../../../../resources/views/post.twig');

        // Verify preview_body fallback to excerpt is removed
        $this->assertStringNotContainsString("previewBody = \$post->getExcerpt();", $postControllerContent);

        // Verify comments section in post.twig is guarded by has_access check
        $this->assertStringContainsString('{% if has_access is not defined or has_access %}', $postTwigContent);
    }
}
