<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Controllers\Front;

use PHPUnit\Framework\TestCase;

final class HideTitleSpacingTest extends TestCase
{
    public function testPageTemplatesConditionallyOmitHeaderWhenTitleIsHidden(): void
    {
        $defaultPageTwig   = file_get_contents(__DIR__ . '/../../../../../resources/views/page.twig');
        $fullWidthTwig     = file_get_contents(__DIR__ . '/../../../../../resources/views/page-templates/full-width.twig');
        $novaPageTwig      = file_get_contents(__DIR__ . '/../../../../../themes/nova/templates/page.twig');
        $vividPageTwig     = file_get_contents(__DIR__ . '/../../../../../themes/vivid/templates/page.twig');
        $darkFullWidthTwig = file_get_contents(__DIR__ . '/../../../../../themes/dark/templates/page-templates/full-width.twig');

        // Default theme page.twig
        $this->assertStringContainsString('{% if not page.hideTitle or page.excerpt %}', $defaultPageTwig);
        $this->assertStringContainsString("{{ page.hideTitle ? 'pt-2 pb-12' : 'pt-3 pb-12' }}", $defaultPageTwig);

        // Default theme full-width.twig
        $this->assertStringContainsString('{% if not page.hideTitle or page.excerpt %}', $fullWidthTwig);
        $this->assertStringContainsString("{{ page.hideTitle ? 'pt-2 pb-12' : 'pt-3 pb-12' }}", $fullWidthTwig);

        // Nova theme page.twig
        $this->assertStringContainsString('{% if not page.hideTitle or page.excerpt %}', $novaPageTwig);
        $this->assertStringContainsString("{{ page.hideTitle ? 'pt-8 pb-20' : 'pt-10 sm:pt-12 pb-20' }}", $novaPageTwig);

        // Vivid theme page.twig
        $this->assertStringContainsString('{% if not page.hideTitle or page.excerpt %}', $vividPageTwig);
        $this->assertStringContainsString("{{ page.hideTitle ? 'pt-4 pb-12' : 'pt-8 pb-12' }}", $vividPageTwig);

        // Dark theme full-width.twig
        $this->assertStringContainsString('{% if not page.hideTitle or page.excerpt %}', $darkFullWidthTwig);
        $this->assertStringContainsString("{{ page.hideTitle ? 'pt-2 pb-12' : 'pt-3 pb-12' }}", $darkFullWidthTwig);
    }

    public function testNewsletterFormUsesResponsiveFlexLayoutForMobileViewports(): void
    {
        $shortcodeProcessorContent = file_get_contents(__DIR__ . '/../../../../../src/Infrastructure/Content/ShortcodeProcessor.php');

        $this->assertStringContainsString('flex flex-col sm:flex-row gap-3 sm:items-end', $shortcodeProcessorContent);
        $this->assertStringContainsString('w-full sm:w-auto rounded-lg btn-primary', $shortcodeProcessorContent);
    }
}
