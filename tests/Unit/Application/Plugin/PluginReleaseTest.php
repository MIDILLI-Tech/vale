<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Plugin;

use PHPUnit\Framework\TestCase;
use ValeApp\Application\Plugin\InvalidPluginException;
use ValeApp\Application\Plugin\PluginRelease;

final class PluginReleaseTest extends TestCase
{
    public function testParsesAValidReleaseFeed(): void
    {
        $release = PluginRelease::fromArray('demo-plugin', [
            'version'         => '1.2.0',
            'download_url'    => 'https://updates.example.test/demo-1.2.0.zip',
            'sha256'          => str_repeat('a', 64),
            'requires_vale'   => '^0.77',
            'requires_php'    => '>=8.3',
            'requires_ext'    => ['CURL', 'curl', 'gd'],
            'requires_plugin' => ['acme-core' => '^1.0', 'acme-utils'],
            'changelog'       => ['Fixed a bug', 'Added a setting'],
        ]);

        self::assertSame('demo-plugin', $release->slug);
        self::assertSame('1.2.0', $release->version);
        self::assertSame('https://updates.example.test/demo-1.2.0.zip', $release->downloadUrl);
        self::assertSame(str_repeat('a', 64), $release->sha256);
        self::assertSame('^0.77', $release->requiresVale);
        self::assertSame('>=8.3', $release->requiresPhp);
        self::assertSame(['curl', 'gd'], $release->requiresExt);
        self::assertSame(['acme-core' => '^1.0', 'acme-utils' => null], $release->requiresPlugin);
        self::assertSame(['Fixed a bug', 'Added a setting'], $release->changelog);
        self::assertTrue($release->isNewerThan('1.1.9'));
        self::assertFalse($release->isNewerThan('1.2.0'));
    }

    public function testRejectsAFeedWithoutAValidVersion(): void
    {
        $this->expectException(InvalidPluginException::class);
        $this->expectExceptionMessage('valid version');

        PluginRelease::fromArray('demo', [
            'download_url' => 'https://updates.example.test/demo.zip',
            'sha256'       => str_repeat('a', 64),
        ]);
    }

    public function testRejectsAFeedWithoutAHash(): void
    {
        $this->expectException(InvalidPluginException::class);
        $this->expectExceptionMessage('SHA-256');

        PluginRelease::fromArray('demo', [
            'version'      => '1.2.0',
            'download_url' => 'https://updates.example.test/demo.zip',
        ]);
    }

    public function testRejectsANonHttpDownloadUrl(): void
    {
        $this->expectException(InvalidPluginException::class);
        $this->expectExceptionMessage('download_url');

        PluginRelease::fromArray('demo', [
            'version'      => '1.2.0',
            'download_url' => 'file:///tmp/demo.zip',
            'sha256'       => str_repeat('a', 64),
        ]);
    }

    public function testReportsPlatformIncompatibility(): void
    {
        $valeRelease = PluginRelease::fromArray('demo', [
            'version'       => '1.2.0',
            'download_url'  => 'https://updates.example.test/demo.zip',
            'sha256'        => str_repeat('a', 64),
            'requires_vale' => '^9.0',
        ]);
        self::assertSame(
            'Requires Vale ^9.0 (running 0.77.3).',
            $valeRelease->incompatibilityReason('0.77.3', '8.3.0'),
        );

        $phpRelease = PluginRelease::fromArray('demo', [
            'version'      => '1.2.0',
            'download_url' => 'https://updates.example.test/demo.zip',
            'sha256'       => str_repeat('a', 64),
            'requires_php' => '>=99',
        ]);
        self::assertSame(
            'Requires PHP >=99 (running 8.3.0).',
            $phpRelease->incompatibilityReason('9.1.0', '8.3.0'),
        );

        $extensionRelease = PluginRelease::fromArray('demo', [
            'version'      => '1.2.0',
            'download_url' => 'https://updates.example.test/demo.zip',
            'sha256'       => str_repeat('a', 64),
            'requires_ext' => ['vl_missing_ext'],
        ]);
        self::assertSame(
            'Requires PHP extension(s): vl_missing_ext.',
            $extensionRelease->incompatibilityReason('9.1.0', '8.3.0'),
        );
    }

    public function testDropsSelfDependencyAndInvalidNames(): void
    {
        $release = PluginRelease::fromArray('demo', [
            'version'         => '1.2.0',
            'download_url'    => 'https://updates.example.test/demo.zip',
            'sha256'          => str_repeat('a', 64),
            'requires_plugin' => [
                'demo'       => '^1.0',
                'acme-core'  => '^1.0',
                'bad name'   => '^1.0',
                42           => 'acme-utils',
            ],
        ]);

        self::assertSame([
            'acme-core'  => '^1.0',
            'acme-utils' => null,
        ], $release->requiresPlugin);
    }
}
