<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Controllers\Admin;

use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use ValeApp\App\Controllers\Admin\ThemeController;
use ValeApp\Domain\Content\Entity\ContentType;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Theme\ThemeManager;
use ValeApp\Infrastructure\Translation\Translator;

final class ThemeControllerTest extends TestCase
{
    private ThemeController $controller;
    private $contentTypes;
    private $posts;

    protected function setUp(): void
    {
        $this->contentTypes = $this->createStub(ContentTypeRepositoryInterface::class);
        $this->posts        = $this->createStub(PostRepositoryInterface::class);

        $this->controller = $this->buildController();
    }

    private function buildController(): ThemeController
    {
        return new ThemeController(
            $this->createStub(Twig::class),
            $this->createStub(ThemeManager::class),
            $this->createStub(DbalSettingsRepository::class),
            $this->createStub(SessionManager::class),
            $this->createStub(Translator::class),
            $this->contentTypes,
            $this->posts
        );
    }

    public function testExpandFieldShortcutsExpandsKnownFields(): void
    {
        $type = new ContentType('TestCPT', new Slug('testcpt'));
        $type->setFieldSchema([
            ['name' => 'test'],
            ['name' => 'vaybe'],
        ]);

        $this->contentTypes->method('findBySlug')->willReturn($type);

        $content = "{{test}} and {{ vaybe }} and {{other}}";

        $result = $this->invokeExpand($content, 'testcpt.twig');

        self::assertStringContainsString(
            "{{ post.getMetaValue('test', '') }}",
            $result
        );
        self::assertStringContainsString(
            "{{ post.getMetaValue('vaybe', '') }}",
            $result
        );
        self::assertStringContainsString('{{other}}', $result);
    }

    public function testExpandFieldShortcutsLeavesUnknownTemplateUntouched(): void
    {
        $this->contentTypes->method('findBySlug')->willReturn(null);

        $content = '{{test}}';
        $result  = $this->invokeExpand($content, 'posts.twig');

        self::assertSame($content, $result);
    }

    public function testExpandFieldShortcutsSkipsPageTemplates(): void
    {
        $type = new ContentType('TestCPT', new Slug('testcpt'));
        $type->setFieldSchema([['name' => 'test']]);

        $this->contentTypes->method('findBySlug')->willReturn($type);

        $content = '{{test}}';
        $result  = $this->invokeExpand($content, 'page-templates/full-width.twig');

        self::assertSame($content, $result);
    }

    public function testExpandFieldShortcutsSkipsContentWithoutPlaceholders(): void
    {
        $contentTypes = $this->createMock(ContentTypeRepositoryInterface::class);
        $contentTypes->expects(self::never())->method('findBySlug');

        $this->contentTypes = $contentTypes;
        $this->controller   = $this->buildController();

        $content = 'no placeholders here';
        $result  = $this->invokeExpand($content, 'testcpt.twig');

        self::assertSame($content, $result);
    }

    private function invokeExpand(string $content, string $file): string
    {
        $method = new \ReflectionMethod(ThemeController::class, 'expandFieldShortcuts');

        return $method->invoke($this->controller, $file, $content);
    }

    public function testBundledThemeMarksOnlyShippedTemplatesAsSystem(): void
    {
        $themeManager = $this->createStub(ThemeManager::class);
        $themeManager->method('isBundledTheme')->willReturn(true);

        $controller = new ThemeController(
            $this->createStub(Twig::class),
            $themeManager,
            $this->createStub(DbalSettingsRepository::class),
            $this->createStub(SessionManager::class),
            $this->createStub(Translator::class),
            $this->contentTypes,
            $this->posts
        );

        $templatesDir = base_path('themes/testtheme/templates');
        $pageDir      = $templatesDir . '/page-templates';
        if (!is_dir($pageDir)) {
            mkdir($pageDir, 0755, true);
        }

        $mainFile       = $templatesDir . '/post.twig';
        $corePageFile   = $pageDir . '/full-width.twig';
        $customPageFile = $pageDir . '/custom.twig';
        file_put_contents($mainFile, '{# main #}');
        file_put_contents($corePageFile, '{# core #}');
        file_put_contents($customPageFile, '{# custom #}');

        try {
            $method    = new \ReflectionMethod(ThemeController::class, 'getThemeTemplates');
            $templates = array_column($method->invoke($controller, 'testtheme'), null, 'path');

            self::assertTrue($templates['post.twig']['is_system']);
            self::assertTrue($templates['page-templates/full-width.twig']['is_system']);
            self::assertFalse($templates['page-templates/custom.twig']['is_system']);
        } finally {
            foreach ([$mainFile, $corePageFile, $customPageFile] as $file) {
                if (file_exists($file)) {
                    @unlink($file);
                }
            }
            @rmdir($pageDir);
            @rmdir($templatesDir);
            @rmdir(dirname($templatesDir));
        }
    }

    public function testSaveColorsStoresColorsInSettings(): void
    {
        $settings     = $this->createMock(DbalSettingsRepository::class);
        $themeManager = $this->createStub(ThemeManager::class);

        $themeManager->method('getColorVariablesSchema')->willReturn([
            '--theme-primary' => ['label' => 'Primary', 'default' => '#111827'],
        ]);

        $settings->expects(self::once())
            ->method('set')
            ->with('theme_default_colors', json_encode(['--theme-primary' => '#123456']), 'theme');

        $controller = new ThemeController(
            $this->createStub(Twig::class),
            $themeManager,
            $settings,
            $this->createStub(SessionManager::class),
            $this->createStub(Translator::class),
            $this->contentTypes,
            $this->posts
        );

        $request = $this->createStub(\Psr\Http\Message\ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'colors' => ['--theme-primary' => '#123456'],
        ]);

        $response = new \Slim\Psr7\Response();
        $res      = $controller->saveColors($request, $response, ['slug' => 'default']);

        self::assertSame(302, $res->getStatusCode());
    }

    public function testResetColorsDeletesColorsFromSettings(): void
    {
        $settings = $this->createMock(DbalSettingsRepository::class);

        $settings->expects(self::once())
            ->method('delete')
            ->with('theme_default_colors');

        $controller = new ThemeController(
            $this->createStub(Twig::class),
            $this->createStub(ThemeManager::class),
            $settings,
            $this->createStub(SessionManager::class),
            $this->createStub(Translator::class),
            $this->contentTypes,
            $this->posts
        );

        $request  = $this->createStub(\Psr\Http\Message\ServerRequestInterface::class);
        $response = new \Slim\Psr7\Response();

        $res = $controller->resetColors($request, $response, ['slug' => 'default']);

        self::assertSame(302, $res->getStatusCode());
    }

    public function testDeleteTemplateRejectsInUsePageTemplate(): void
    {
        $session    = $this->createMock(SessionManager::class);
        $translator = $this->createStub(Translator::class);
        $translator->method('get')->willReturnCallback(fn ($key) => $key);

        $lookedUp = [];
        $posts    = $this->createStub(PostRepositoryInterface::class);
        $posts->method('countByPageTemplate')->willReturnCallback(
            static function (string $template) use (&$lookedUp): int {
                $lookedUp[] = $template;
                return 2;
            }
        );
        $this->posts = $posts;

        $session->expects(self::once())
            ->method('set')
            ->with('flash_error', 'This template is in use and cannot be deleted.');

        $controller = new ThemeController(
            $this->createStub(Twig::class),
            $this->createStub(ThemeManager::class),
            $this->createStub(DbalSettingsRepository::class),
            $session,
            $translator,
            $this->contentTypes,
            $this->posts
        );

        // Create temporary theme directory and template file
        $themeDir = base_path('themes/testtheme/templates/page-templates');
        if (!is_dir($themeDir)) {
            mkdir($themeDir, 0755, true);
        }
        $testFile = $themeDir . '/custom-tpl.twig';
        file_put_contents($testFile, '{# test #}');

        try {
            $request = $this->createStub(\Psr\Http\Message\ServerRequestInterface::class);
            $request->method('getParsedBody')->willReturn(['file' => 'page-templates/custom-tpl.twig']);
            $response = new \Slim\Psr7\Response();

            $res = $controller->deleteTemplate($request, $response, ['slug' => 'testtheme']);

            self::assertSame(302, $res->getStatusCode());
            self::assertFileExists($testFile);
            self::assertSame(['custom-tpl'], $lookedUp);
        } finally {
            if (file_exists($testFile)) {
                @unlink($testFile);
            }
            @rmdir($themeDir);
            @rmdir(dirname($themeDir));
        }
    }

    public function testDeleteTemplateRejectsCorePageTemplate(): void
    {
        $session    = $this->createMock(SessionManager::class);
        $translator = $this->createStub(Translator::class);
        $translator->method('get')->willReturnCallback(fn ($key) => $key);

        // Core page templates are rejected before the in-use lookup happens.
        $lookedUp = [];
        $posts    = $this->createStub(PostRepositoryInterface::class);
        $posts->method('countByPageTemplate')->willReturnCallback(
            static function (string $template) use (&$lookedUp): int {
                $lookedUp[] = $template;
                return 0;
            }
        );
        $this->posts = $posts;

        $session->expects(self::once())
            ->method('set')
            ->with('flash_error', 'Core page templates cannot be deleted.');

        $controller = new ThemeController(
            $this->createStub(Twig::class),
            $this->createStub(ThemeManager::class),
            $this->createStub(DbalSettingsRepository::class),
            $session,
            $translator,
            $this->contentTypes,
            $this->posts
        );

        // Create temporary theme directory and template file matching core template name full-width.twig
        $themeDir = base_path('themes/testtheme/templates/page-templates');
        if (!is_dir($themeDir)) {
            mkdir($themeDir, 0755, true);
        }
        $testFile = $themeDir . '/full-width.twig';
        file_put_contents($testFile, '{# full-width #}');

        try {
            $request = $this->createStub(\Psr\Http\Message\ServerRequestInterface::class);
            $request->method('getParsedBody')->willReturn(['file' => 'page-templates/full-width.twig']);
            $response = new \Slim\Psr7\Response();

            $res = $controller->deleteTemplate($request, $response, ['slug' => 'testtheme']);

            self::assertSame(302, $res->getStatusCode());
            self::assertFileExists($testFile);
            self::assertSame([], $lookedUp);
        } finally {
            if (file_exists($testFile)) {
                @unlink($testFile);
            }
            @rmdir($themeDir);
            @rmdir(dirname($themeDir));
        }
    }

    public function testDeleteTemplateDeletesUnusedCustomPageTemplate(): void
    {
        $session    = $this->createMock(SessionManager::class);
        $translator = $this->createStub(Translator::class);
        $translator->method('get')->willReturnCallback(fn ($key, $params = []) => strtr($key, $params));

        $lookedUp = [];
        $posts    = $this->createStub(PostRepositoryInterface::class);
        $posts->method('countByPageTemplate')->willReturnCallback(
            static function (string $template) use (&$lookedUp): int {
                $lookedUp[] = $template;
                return 0;
            }
        );
        $this->posts = $posts;

        $session->expects(self::once())
            ->method('set')
            ->with('flash_success', 'Template "unused-tpl.twig" deleted.');

        $controller = new ThemeController(
            $this->createStub(Twig::class),
            $this->createStub(ThemeManager::class),
            $this->createStub(DbalSettingsRepository::class),
            $session,
            $translator,
            $this->contentTypes,
            $this->posts
        );

        $themeDir = base_path('themes/testtheme/templates/page-templates');
        if (!is_dir($themeDir)) {
            mkdir($themeDir, 0755, true);
        }
        $testFile = $themeDir . '/unused-tpl.twig';
        file_put_contents($testFile, '{# unused #}');

        try {
            $request = $this->createStub(\Psr\Http\Message\ServerRequestInterface::class);
            $request->method('getParsedBody')->willReturn(['file' => 'page-templates/unused-tpl.twig']);
            $response = new \Slim\Psr7\Response();

            $res = $controller->deleteTemplate($request, $response, ['slug' => 'testtheme']);

            self::assertSame(302, $res->getStatusCode());
            self::assertFileDoesNotExist($testFile);
            self::assertSame(['unused-tpl'], $lookedUp);
        } finally {
            if (file_exists($testFile)) {
                @unlink($testFile);
            }
            @rmdir($themeDir);
            @rmdir(dirname($themeDir));
        }
    }

    public function testSaveTemplateInvalidatesOnlySavedTemplateCache(): void
    {
        $themeDir = base_path('themes/cachetest/templates');
        $cacheDir = sys_get_temp_dir() . '/vl_theme_cache_' . bin2hex(random_bytes(6));
        if (!is_dir($themeDir)) {
            mkdir($themeDir, 0755, true);
        }

        $testFile  = $themeDir . '/test.twig';
        $otherFile = $themeDir . '/other.twig';
        file_put_contents($testFile, 'Original');
        file_put_contents($otherFile, 'Other');

        $twig = Twig::create([$themeDir], [
            'cache'       => $cacheDir,
            'auto_reload' => false,
        ]);

        try {
            $twig->fetch('test.twig');
            $twig->fetch('other.twig');

            $environment = $twig->getEnvironment();
            $cache       = $environment->getCache(false);
            $testKey     = $cache->generateKey('test.twig', $environment->getTemplateClass('test.twig'));
            $otherKey    = $cache->generateKey('other.twig', $environment->getTemplateClass('other.twig'));

            self::assertFileExists($testKey);
            self::assertFileExists($otherKey);

            $controller = new ThemeController(
                $twig,
                $this->createStub(ThemeManager::class),
                $this->createStub(DbalSettingsRepository::class),
                $this->createStub(SessionManager::class),
                $this->createStub(Translator::class),
                $this->contentTypes,
                $this->posts
            );

            $request = $this->createStub(\Psr\Http\Message\ServerRequestInterface::class);
            $request->method('getParsedBody')->willReturn([
                'file'    => 'test.twig',
                'content' => 'Updated',
            ]);

            $response = $controller->saveTemplate(
                $request,
                new \Slim\Psr7\Response(),
                ['slug' => 'cachetest']
            );

            self::assertSame(200, $response->getStatusCode());
            self::assertFileDoesNotExist($testKey);
            self::assertFileExists($otherKey);
        } finally {
            foreach ([$testFile, $otherFile] as $file) {
                if (file_exists($file)) {
                    @unlink($file);
                }
            }
            @rmdir($themeDir);
            @rmdir(dirname($themeDir));
            $this->removeDirectory($cacheDir);
        }
    }

    public function testDeleteTemplateInvalidatesCompiledCacheBeforeRemovingFile(): void
    {
        $themeDir = base_path('themes/cachetest/templates');
        $pageDir  = $themeDir . '/page-templates';
        $cacheDir = sys_get_temp_dir() . '/vl_theme_cache_' . bin2hex(random_bytes(6));
        if (!is_dir($pageDir)) {
            mkdir($pageDir, 0755, true);
        }

        $template = $pageDir . '/cache-delete-tpl.twig';
        file_put_contents($template, 'Delete me');

        $twig = Twig::create([$themeDir], [
            'cache'       => $cacheDir,
            'auto_reload' => false,
        ]);

        try {
            $name = 'page-templates/cache-delete-tpl.twig';
            $twig->fetch($name);

            $environment = $twig->getEnvironment();
            $cache       = $environment->getCache(false);
            $cacheKey    = $cache->generateKey($name, $environment->getTemplateClass($name));
            self::assertFileExists($cacheKey);

            $posts = $this->createStub(PostRepositoryInterface::class);
            $posts->method('countByPageTemplate')->willReturn(0);

            $controller = new ThemeController(
                $twig,
                $this->createStub(ThemeManager::class),
                $this->createStub(DbalSettingsRepository::class),
                $this->createStub(SessionManager::class),
                $this->createStub(Translator::class),
                $this->contentTypes,
                $posts
            );

            $request = $this->createStub(\Psr\Http\Message\ServerRequestInterface::class);
            $request->method('getParsedBody')->willReturn(['file' => $name]);

            $response = $controller->deleteTemplate(
                $request,
                new \Slim\Psr7\Response(),
                ['slug' => 'cachetest']
            );

            self::assertSame(302, $response->getStatusCode());
            self::assertFileDoesNotExist($cacheKey);
            self::assertFileDoesNotExist($template);
        } finally {
            if (file_exists($template)) {
                @unlink($template);
            }
            @rmdir($pageDir);
            @rmdir($themeDir);
            @rmdir(dirname($themeDir));
            $this->removeDirectory($cacheDir);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }

        @rmdir($dir);
    }
}
