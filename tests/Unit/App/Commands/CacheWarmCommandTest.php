<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Commands;

use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use ValeApp\App\Commands\CacheWarmCommand;

final class CacheWarmCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/cache_warm_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        file_put_contents($this->tempDir . '/test.twig', '{{ country_name("TR") }} {{ language_name("en") }}');
    }

    protected function tearDown(): void
    {
        if (is_file($this->tempDir . '/test.twig')) {
            unlink($this->tempDir . '/test.twig');
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function testWarmViewsWithCustomFunctions(): void
    {
        $loader = new FilesystemLoader([$this->tempDir]);
        $env    = new Environment($loader);

        // Register stubs as done in CacheWarmCommand
        $stub = fn () => '';
        $env->addFunction(new \Twig\TwigFunction('country_name', $stub));
        $env->addFunction(new \Twig\TwigFunction('language_name', $stub));

        $twig = $this->createStub(Twig::class);
        $twig->method('getEnvironment')->willReturn($env);

        $command = new CacheWarmCommand($twig);
        $input   = new ArrayInput(['type' => 'views']);
        $output  = new BufferedOutput();

        $exitCode = $command->run($input, $output);

        $this->assertSame(0, $exitCode);
        $outputText = $output->fetch();
        $this->assertStringContainsString('pre-compiled 1 templates', $outputText);
        $this->assertStringContainsString('Done. Cache warmed successfully.', $outputText);
    }

    public function testWarmViewsHandlesUndefinedFiltersAndFunctionsDynamically(): void
    {
        file_put_contents($this->tempDir . '/unknown.twig', '{{ 123|unknown_filter }} {{ unknown_function() }}');

        $loader = new FilesystemLoader([$this->tempDir]);
        $env    = new Environment($loader);

        $stub     = fn () => '';
        $htmlStub = fn () => new \Twig\Markup('', 'UTF-8');

        $env->registerUndefinedFunctionCallback(static function (string $name) use ($stub, $htmlStub): \Twig\TwigFunction|false {
            if (in_array($name, ['csrf_field', 'theme_hook'], true)) {
                return new \Twig\TwigFunction($name, $htmlStub, ['is_safe' => ['html']]);
            }
            return new \Twig\TwigFunction($name, $stub);
        });

        $env->registerUndefinedFilterCallback(static function (string $name) use ($stub): \Twig\TwigFilter|false {
            return new \Twig\TwigFilter($name, $stub);
        });

        $twig = $this->createStub(Twig::class);
        $twig->method('getEnvironment')->willReturn($env);

        $command = new CacheWarmCommand($twig);
        $input   = new ArrayInput(['type' => 'views']);
        $output  = new BufferedOutput();

        $exitCode = $command->run($input, $output);

        $this->assertSame(0, $exitCode);
        $outputText = $output->fetch();
        $this->assertStringContainsString('pre-compiled 2 templates', $outputText);
        $this->assertStringNotContainsString('skipped', $outputText);

        if (is_file($this->tempDir . '/unknown.twig')) {
            unlink($this->tempDir . '/unknown.twig');
        }
    }
}
