<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure\Translation;

use PHPUnit\Framework\TestCase;
use ValeApp\Infrastructure\Translation\Translator;

class TranslatorTest extends TestCase
{
    private string $langDir;

    protected function setUp(): void
    {
        $this->langDir = sys_get_temp_dir() . '/vale_lang_' . uniqid();
        mkdir($this->langDir);

        file_put_contents($this->langDir . '/en.json', json_encode([
            'Welcome'     => 'Welcome',
            'Hello :name' => 'Hello :name'
        ]));

        file_put_contents($this->langDir . '/fr.json', json_encode([
            'Welcome'     => '',
            'Hello :name' => 'Bonjour :name'
        ]));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->langDir . '/*.json'));
        rmdir($this->langDir);
    }

    public function testGetFallbackToEnglishOnEmptyString(): void
    {
        $translator = new Translator($this->langDir, 'fr', ['en', 'fr']);

        // Should fallback to English because fr.json has an empty string for 'Welcome'
        $this->assertEquals('Welcome', $translator->get('Welcome'));

        // Should use French because fr.json has a non-empty string
        $this->assertEquals('Bonjour World', $translator->get('Hello :name', [':name' => 'World']));
    }

    public function testGetFallbackToEnglishOnMissingKey(): void
    {
        $translator = new Translator($this->langDir, 'fr', ['en', 'fr']);

        // Key exists in EN but not in FR
        file_put_contents($this->langDir . '/en.json', json_encode(['OnlyInEn' => 'Only In English']));
        // Force reload by creating new instance
        $translator = new Translator($this->langDir, 'fr', ['en', 'fr']);

        $this->assertEquals('Only In English', $translator->get('OnlyInEn'));
    }
}
