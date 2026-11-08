<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Security\Encrypter;

final class DbalSettingsRepositoryTest extends TestCase
{
    /**
     * @param array<string, string> $cache
     */
    private function repositoryWithCache(array $cache): DbalSettingsRepository
    {
        $repo = new DbalSettingsRepository(
            $this->createStub(Connection::class),
            $this->createStub(Encrypter::class),
        );

        $reflection = new \ReflectionClass($repo);
        $cacheProp  = $reflection->getProperty('cache');
        $cacheProp->setValue($repo, $cache);

        $loadedProp = $reflection->getProperty('loaded');
        $loadedProp->setValue($repo, true);

        return $repo;
    }

    public function testGetLocalizedReturnsDefaultLocaleValue(): void
    {
        $repo = $this->repositoryWithCache([
            'default_locale' => 'en',
            'site_tagline'   => 'Hello world',
        ]);

        self::assertSame('Hello world', $repo->getLocalized('site_tagline', 'en'));
    }

    public function testGetLocalizedReturnsLocaleSpecificValue(): void
    {
        $repo = $this->repositoryWithCache([
            'default_locale'                  => 'en',
            'multi_language_content'          => '1',
            'i18n_display_originals_fallback' => '1',
            'site_tagline'                    => 'Hello world',
            'site_tagline_tr'                 => 'Merhaba dünya',
        ]);

        self::assertSame('Merhaba dünya', $repo->getLocalized('site_tagline', 'tr'));
    }

    public function testGetLocalizedFallsBackToDefaultWhenTranslationMissing(): void
    {
        $repo = $this->repositoryWithCache([
            'default_locale'                    => 'en',
            'multi_language_content'            => '1',
            'i18n_display_originals_fallback'   => '1',
            'site_tagline'                      => 'Hello world',
        ]);

        self::assertSame('Hello world', $repo->getLocalized('site_tagline', 'tr'));
    }

    public function testGetLocalizedDoesNotFallbackWhenDisabled(): void
    {
        $repo = $this->repositoryWithCache([
            'default_locale'                    => 'en',
            'multi_language_content'            => '1',
            'i18n_display_originals_fallback'   => '0',
            'site_tagline'                      => 'Hello world',
        ]);

        self::assertNull($repo->getLocalized('site_tagline', 'tr', null));
    }
}
