<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Controllers\Front;

use PHPUnit\Framework\TestCase;

final class PostDateSettingsTest extends TestCase
{
    public function testPostAndBlogTemplatesContainModificationDateAndTimestampLogic(): void
    {
        $postTwig    = file_get_contents(__DIR__ . '/../../../../../resources/views/post.twig');
        $blogTwig    = file_get_contents(__DIR__ . '/../../../../../resources/views/blog.twig');
        $servicesPhp = file_get_contents(__DIR__ . '/../../../../../config/services.php');

        $this->assertStringContainsString('show_modification_date_on_posts', $postTwig);
        $this->assertStringContainsString('show_timestamp_on_posts', $postTwig);
        $this->assertStringContainsString('show_modification_date_on_posts', $blogTwig);
        $this->assertStringContainsString('show_timestamp_on_posts', $blogTwig);

        $this->assertStringContainsString("show_modification_date_on_posts", $servicesPhp);
        $this->assertStringContainsString("show_timestamp_on_posts", $servicesPhp);
        $this->assertStringContainsString("bool \$withTime = false", $servicesPhp);
    }
}
