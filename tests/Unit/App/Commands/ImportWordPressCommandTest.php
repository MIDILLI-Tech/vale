<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Commands;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use ValeApp\App\Commands\ImportWordPressCommand;
use ValeApp\Infrastructure\Auth\PasswordHasher;

final class ImportWordPressCommandTest extends TestCase
{
    private $valeDbMock;
    private $hasher;
    private $command;

    protected function setUp(): void
    {
        $this->valeDbMock = $this->createStub(Connection::class);
        $this->hasher     = new PasswordHasher();

        $this->command = new ImportWordPressCommand(
            $this->valeDbMock,
            $this->hasher
        );
    }

    public function testCombiningSpecificSwitchesDeactivatesAll(): void
    {
        // Define options as in the configure() of the command
        $definition = new InputDefinition([
            new InputOption('wp-prefix', null, InputOption::VALUE_REQUIRED, '', 'wp_'),
            new InputOption('dry-run', null, InputOption::VALUE_NONE),
            new InputOption('download-media', null, InputOption::VALUE_NONE),
            new InputOption('all', null, InputOption::VALUE_NONE),
            new InputOption('posts', null, InputOption::VALUE_NONE),
            new InputOption('woocommerce', null, InputOption::VALUE_NONE),
            new InputOption('users', null, InputOption::VALUE_NONE),
            new InputOption('subscribers', null, InputOption::VALUE_NONE),
            new InputOption('seo', null, InputOption::VALUE_NONE),
            new InputOption('db-dump', null, InputOption::VALUE_REQUIRED),
            new InputOption('wp-db', null, InputOption::VALUE_REQUIRED),
            new InputOption('file', null, InputOption::VALUE_REQUIRED),
            new InputOption('fix-slugs', null, InputOption::VALUE_NONE),
            new InputOption('cleanup-vale', null, InputOption::VALUE_NONE),
            new InputOption('type', null, InputOption::VALUE_OPTIONAL),
        ]);

        $input = new ArrayInput([
            '--users' => true,
            '--seo'   => true,
        ], $definition);

        $output = new BufferedOutput();

        // Run should proceed to execution, but let's check command's parsed properties using Reflection
        $this->command->run($input, $output);

        $reflection = new \ReflectionClass(ImportWordPressCommand::class);

        $importAllProp = $reflection->getProperty('importAll');
        $this->assertFalse($importAllProp->getValue($this->command));

        $importUsersProp = $reflection->getProperty('importUsersOnly');
        $this->assertTrue($importUsersProp->getValue($this->command));

        $importSeoProp = $reflection->getProperty('importSeoOnly');
        $this->assertTrue($importSeoProp->getValue($this->command));
    }

    public function testDefaultLocaleResolvesToSettingOrDefault(): void
    {
        // Mock DB fetch for setting
        $this->valeDbMock->method('fetchOne')
            ->willReturn('tr');

        // Access private helper using reflection
        $reflection = new \ReflectionClass(ImportWordPressCommand::class);
        $method     = $reflection->getMethod('getDefaultFallbackLocale');

        $locale = $method->invoke($this->command);
        $this->assertSame('tr', $locale);
    }

    public function testPolylangLanguageDetectionGracefulFallback(): void
    {
        // No wpDb set, should fallback to default locale
        $reflection = new \ReflectionClass(ImportWordPressCommand::class);

        $prop = $reflection->getProperty('defaultLocale');
        $prop->setValue($this->command, 'de');

        $method = $reflection->getMethod('wpGetObjectLanguage');

        $lang = $method->invoke($this->command, 123);
        $this->assertSame('de', $lang);
    }

    public function testWxrGetItemLanguageExtractor(): void
    {
        $xmlStr = <<<XML
<item>
    <category domain="language" nicename="fr"><![CDATA[Français]]></category>
</item>
XML;
        $xml = new SimpleXMLElement($xmlStr);

        $reflection = new \ReflectionClass(ImportWordPressCommand::class);
        $method     = $reflection->getMethod('wxrGetItemLanguage');

        $lang = $method->invoke($this->command, $xml);
        $this->assertSame('fr', $lang);
    }

    public function testExtractSerializedArrayHelper(): void
    {
        $reflection = new \ReflectionClass(ImportWordPressCommand::class);
        $method     = $reflection->getMethod('extractSerializedArray');

        $serialized = 'a:2:{s:2:"tr";i:123;s:2:"en";i:456;}';
        $result     = $method->invoke($this->command, 'not-serialized', $serialized);

        $this->assertIsArray($result);
        $this->assertSame(123, $result['tr']);
        $this->assertSame(456, $result['en']);
    }

    public function testResolveWxrPolylangTranslationsUpdatesTableRecords(): void
    {
        $this->useMockValeDb();
        $reflection = new \ReflectionClass(ImportWordPressCommand::class);

        // Populate postMap and wxrTranslations
        $postMapProp = $reflection->getProperty('postMap');
        $postMapProp->setValue($this->command, [
            1001 => 10,
            1002 => 11,
        ]);

        $translationsProp = $reflection->getProperty('wxrTranslations');
        $translationsProp->setValue($this->command, [
            [
                'taxonomy' => 'post_translations',
                'data'     => [
                    'en' => 1001,
                    'tr' => 1002,
                ],
            ]
        ]);

        // Expect update queries for both posts: setting language code and translation_of pointers
        // 1. language update to 'en' (checks products table first)
        // 2. language update to 'tr' (checks products table first)
        // 3. translation_of update to 10 (checks products table first)
        $this->valeDbMock->expects($this->exactly(3))
            ->method('fetchOne')
            ->willReturn(null); // Means it is post table, not products

        $this->valeDbMock->expects($this->exactly(3))
            ->method('update')
            ->willReturnCallback(function ($table, $data, $identifier) {
                if ($table === 'vl_posts' || str_contains($table, 'posts')) {
                    if (isset($data['language'])) {
                        $this->assertContains($data['language'], ['en', 'tr']);
                    }
                    if (isset($data['translation_of'])) {
                        $this->assertSame(10, $data['translation_of']);
                    }
                }
                return 1;
            });

        $method = $reflection->getMethod('resolveWxrPolylangTranslations');
        $method->invoke($this->command);
    }

    public function testParseWooRelatedIdListHandlesSerializedAndCsv(): void
    {
        $reflection = new \ReflectionClass(ImportWordPressCommand::class);
        $method     = $reflection->getMethod('parseWooRelatedIdList');

        $this->assertSame([], $method->invoke($this->command, ''));
        $this->assertSame([], $method->invoke($this->command, 'a:0:{}'));

        $serialized = $method->invoke($this->command, 'a:3:{i:0;i:101;i:1;i:202;i:2;i:101;}');
        $this->assertSame([101, 202], $serialized);

        $csv = $method->invoke($this->command, '101, 202 ,303');
        $this->assertSame([101, 202, 303], $csv);
    }

    public function testResolveWooRelatedIdsMapsOnlyImportedProducts(): void
    {
        $reflection = new \ReflectionClass(ImportWordPressCommand::class);
        $wooMapProp = $reflection->getProperty('wooProductMap');
        $wooMapProp->setValue($this->command, [200 => 20, 300 => 30]);

        $method = $reflection->getMethod('resolveWooRelatedIds');

        $this->assertSame([20, 30], $method->invoke($this->command, [200, 300]));
        $this->assertSame([30], $method->invoke($this->command, [500, 300, 500]));
    }

    public function testLinkWooRelatedProductsUpdatesResolvedRelationships(): void
    {
        $this->useMockValeDb();
        $reflection = new \ReflectionClass(ImportWordPressCommand::class);

        $wooMapProp = $reflection->getProperty('wooProductMap');
        $wooMapProp->setValue($this->command, [200 => 20, 300 => 30]);

        $wpDbMock = $this->createStub(Connection::class);
        $wpDbMock->method('fetchAllAssociative')
            ->willReturn([
                ['post_id' => '200', 'meta_key' => '_upsell_ids', 'meta_value' => 'a:1:{i:0;i:300;}'],
                ['post_id' => '200', 'meta_key' => '_crosssell_ids', 'meta_value' => 'a:1:{i:0;i:999;}'],
                ['post_id' => '300', 'meta_key' => '_upsell_ids', 'meta_value' => 'a:1:{i:0;i:200;}'],
            ]);

        $wpDbProp = $reflection->getProperty('wpDb');
        $wpDbProp->setValue($this->command, $wpDbMock);

        $wpPrefixProp = $reflection->getProperty('wpPrefix');
        $wpPrefixProp->setValue($this->command, 'wp_');

        $this->setIo($reflection);

        $this->valeDbMock->expects($this->exactly(2))
            ->method('update')
            ->willReturnCallback(function ($table, $data, $identifier) {
                $this->assertSame('vl_products', $table);
                if ($identifier['id'] === 20) {
                    $this->assertSame('[30]', $data['upsell_ids']);
                } elseif ($identifier['id'] === 30) {
                    $this->assertSame('[20]', $data['upsell_ids']);
                } else {
                    $this->fail('Unexpected product id: ' . $identifier['id']);
                }
                return 1;
            });

        $method = $reflection->getMethod('linkWooRelatedProducts');
        $method->invoke($this->command);
    }

    public function testWxrLinkRelatedProductsLinksFromItemPostmeta(): void
    {
        $this->useMockValeDb();
        $reflection = new \ReflectionClass(ImportWordPressCommand::class);

        $wooMapProp = $reflection->getProperty('wooProductMap');
        $wooMapProp->setValue($this->command, [200 => 20, 300 => 30]);

        $this->setIo($reflection);

        $xml1 = new SimpleXMLElement(<<<XML
<item xmlns:wp="http://wordpress.org/export/1.2/">
    <wp:post_id><![CDATA[200]]></wp:post_id>
    <wp:post_type><![CDATA[product]]></wp:post_type>
    <wp:postmeta>
        <wp:meta_key><![CDATA[_upsell_ids]]></wp:meta_key>
        <wp:meta_value><![CDATA[a:1:{i:0;i:300;}]]></wp:meta_value>
    </wp:postmeta>
</item>
XML);

        $xml2 = new SimpleXMLElement(<<<XML
<item xmlns:wp="http://wordpress.org/export/1.2/">
    <wp:post_id><![CDATA[300]]></wp:post_id>
    <wp:post_type><![CDATA[product]]></wp:post_type>
    <wp:postmeta>
        <wp:meta_key><![CDATA[_crosssell_ids]]></wp:meta_key>
        <wp:meta_value><![CDATA[a:1:{i:0;i:200;}]]></wp:meta_value>
    </wp:postmeta>
</item>
XML);

        $xml3 = new SimpleXMLElement(<<<XML
<item xmlns:wp="http://wordpress.org/export/1.2/">
    <wp:post_id><![CDATA[400]]></wp:post_id>
    <wp:post_type><![CDATA[post]]></wp:post_type>
    <wp:postmeta>
        <wp:meta_key><![CDATA[_upsell_ids]]></wp:meta_key>
        <wp:meta_value><![CDATA[a:1:{i:0;i:200;}]]></wp:meta_value>
    </wp:postmeta>
</item>
XML);

        $this->valeDbMock->expects($this->exactly(2))
            ->method('update')
            ->willReturnCallback(function ($table, $data, $identifier) {
                $this->assertSame('vl_products', $table);
                if ($identifier['id'] === 20) {
                    $this->assertSame('[30]', $data['upsell_ids']);
                } elseif ($identifier['id'] === 30) {
                    $this->assertSame('[20]', $data['cross_sell_ids']);
                } else {
                    $this->fail('Unexpected product id: ' . $identifier['id']);
                }
                return 1;
            });

        $method = $reflection->getMethod('wxrLinkRelatedProducts');
        $method->invoke($this->command, [$xml1, $xml2, $xml3]);
    }

    public function testWxrImportVariationsSetsParentMinPriceWhenParentHasNoPrice(): void
    {
        $reflection = new \ReflectionClass(ImportWordPressCommand::class);

        $postMapProp = $reflection->getProperty('postMap');
        $postMapProp->setValue($this->command, [200 => 20]);

        $this->setIo($reflection);

        $xml1 = new SimpleXMLElement(<<<XML
<item xmlns:wp="http://wordpress.org/export/1.2/">
    <wp:post_id><![CDATA[301]]></wp:post_id>
    <wp:post_parent><![CDATA[200]]></wp:post_parent>
    <wp:postmeta>
        <wp:meta_key><![CDATA[_regular_price]]></wp:meta_key>
        <wp:meta_value><![CDATA[25.00]]></wp:meta_value>
    </wp:postmeta>
</item>
XML);

        $xml2 = new SimpleXMLElement(<<<XML
<item xmlns:wp="http://wordpress.org/export/1.2/">
    <wp:post_id><![CDATA[302]]></wp:post_id>
    <wp:post_parent><![CDATA[200]]></wp:post_parent>
    <wp:postmeta>
        <wp:meta_key><![CDATA[_regular_price]]></wp:meta_key>
        <wp:meta_value><![CDATA[10.00]]></wp:meta_value>
    </wp:postmeta>
</item>
XML);

        // Insert two variation rows, return a stable lastInsertId.
        $this->valeDbMock->method('insert')
            ->willReturnCallback(function () {
                return 1;
            });
        $this->valeDbMock->method('lastInsertId')->willReturn(500);

        // Parent currently has price 0 -> should be upated to the min (10.00).
        $this->valeDbMock->method('fetchOne')
            ->willReturnCallback(fn ($sql, $params = []) => '0');

        $priceUpdates = [];
        $this->valeDbMock->method('update')
            ->willReturnCallback(function ($table, $data, $identifier) use (&$priceUpdates) {
                if (isset($data['price'])) {
                    $priceUpdates[] = [$identifier['id'] => $data['price']];
                }
                return 1;
            });

        $method = $reflection->getMethod('wxrImportVariations');
        $method->invoke($this->command, [$xml1, $xml2]);

        $this->assertSame([20 => 10.0], $priceUpdates[0] ?? null);
    }

    public function testWxrImportVariationsUpgradesParentToDigitalWhenEveryVariationIsVirtual(): void
    {
        $reflection = new \ReflectionClass(ImportWordPressCommand::class);

        $postMapProp = $reflection->getProperty('postMap');
        $postMapProp->setValue($this->command, [200 => 20, 201 => 21]);

        $this->setIo($reflection);

        $virtualVariation = static function (int $postId, int $parentPostId): SimpleXMLElement {
            return new SimpleXMLElement(<<<XML
<item xmlns:wp="http://wordpress.org/export/1.2/">
    <wp:post_id><![CDATA[{$postId}]]></wp:post_id>
    <wp:post_parent><![CDATA[{$parentPostId}]]></wp:post_parent>
    <wp:postmeta>
        <wp:meta_key><![CDATA[_virtual]]></wp:meta_key>
        <wp:meta_value><![CDATA[yes]]></wp:meta_value>
        <wp:meta_key><![CDATA[_downloadable]]></wp:meta_key>
        <wp:meta_value><![CDATA[no]]></wp:meta_value>
        <wp:meta_key><![CDATA[_regular_price]]></wp:meta_key>
        <wp:meta_value><![CDATA[25.00]]></wp:meta_value>
    </wp:postmeta>
</item>
XML);
        };

        $physicalVariation = new SimpleXMLElement(<<<XML
<item xmlns:wp="http://wordpress.org/export/1.2/">
    <wp:post_id><![CDATA[304]]></wp:post_id>
    <wp:post_parent><![CDATA[201]]></wp:post_parent>
    <wp:postmeta>
        <wp:meta_key><![CDATA[_virtual]]></wp:meta_key>
        <wp:meta_value><![CDATA[no]]></wp:meta_value>
        <wp:meta_key><![CDATA[_downloadable]]></wp:meta_key>
        <wp:meta_value><![CDATA[no]]></wp:meta_value>
        <wp:meta_key><![CDATA[_regular_price]]></wp:meta_key>
        <wp:meta_value><![CDATA[40.00]]></wp:meta_value>
    </wp:postmeta>
</item>
XML);

        $this->valeDbMock->method('insert')->willReturn(1);
        $this->valeDbMock->method('lastInsertId')->willReturn(500);
        $this->valeDbMock->method('fetchOne')->willReturn('0');

        $shippingUpdates = [];
        $this->valeDbMock->method('update')
            ->willReturnCallback(function ($table, $data, $identifier) use (&$shippingUpdates) {
                if (isset($data['shipping_class'])) {
                    $shippingUpdates[] = [$identifier['id'] => $data['shipping_class']];
                }
                return 1;
            });

        $method = $reflection->getMethod('wxrImportVariations');
        $method->invoke($this->command, [
            $virtualVariation(301, 200),
            $virtualVariation(302, 200),
            $virtualVariation(303, 201),
            $physicalVariation,
        ]);

        // Only the all-digital parent is upgraded; the mixed parent keeps its
        // imported physical class so its physical variation still ships.
        $this->assertSame([[20 => 'digital']], $shippingUpdates);
    }

    public function testImportWooVariationsSetsParentMinPriceWhenParentHasNoPrice(): void
    {
        $reflection = new \ReflectionClass(ImportWordPressCommand::class);

        $wpDbMock = $this->createStub(Connection::class);
        $wpDbMock->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls(
                // variation list for the parent
                [
                    ['ID' => 301, 'post_type' => 'product_variation', 'post_parent' => 200, 'post_status' => 'publish'],
                    ['ID' => 302, 'post_type' => 'product_variation', 'post_parent' => 200, 'post_status' => 'publish'],
                ],
                // meta for variation 301
                [
                    ['meta_key' => '_regular_price', 'meta_value' => '25.00'],
                ],
                // meta for variation 302
                [
                    ['meta_key' => '_regular_price', 'meta_value' => '10.00'],
                ],
            );
        $wpDbProp = $reflection->getProperty('wpDb');
        $wpDbProp->setValue($this->command, $wpDbMock);

        $wpPrefixProp = $reflection->getProperty('wpPrefix');
        $wpPrefixProp->setValue($this->command, 'wp_');

        $this->setIo($reflection);

        $this->valeDbMock->method('insert')->willReturn(1);
        $this->valeDbMock->method('lastInsertId')->willReturn(500);
        $this->valeDbMock->method('fetchOne')->willReturn('0');

        $priceUpdates = [];
        $this->valeDbMock->method('update')
            ->willReturnCallback(function ($table, $data, $identifier) use (&$priceUpdates) {
                if (isset($data['price'])) {
                    $priceUpdates[] = [$identifier['id'] => $data['price']];
                }
                return 1;
            });

        $method = $reflection->getMethod('importWooVariations');
        $method->invoke($this->command, 200, 20);

        $this->assertSame([20 => 10.0], $priceUpdates[0] ?? null);
    }

    public function testImportWooVariationsUpgradesParentToDigitalWhenEveryVariationIsVirtual(): void
    {
        $reflection = new \ReflectionClass(ImportWordPressCommand::class);

        $wpDbMock = $this->createStub(Connection::class);
        $wpDbMock->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls(
                // parent 200: all variations virtual -> parent becomes digital
                [
                    ['ID' => 301, 'post_type' => 'product_variation', 'post_parent' => 200, 'post_status' => 'publish'],
                    ['ID' => 302, 'post_type' => 'product_variation', 'post_parent' => 200, 'post_status' => 'publish'],
                ],
                [
                    ['meta_key' => '_virtual', 'meta_value' => 'yes'],
                    ['meta_key' => '_downloadable', 'meta_value' => 'no'],
                    ['meta_key' => '_regular_price', 'meta_value' => '25.00'],
                ],
                [
                    ['meta_key' => '_virtual', 'meta_value' => 'yes'],
                    ['meta_key' => '_downloadable', 'meta_value' => 'no'],
                    ['meta_key' => '_regular_price', 'meta_value' => '10.00'],
                ],
                // parent 201: one physical variation -> parent stays physical
                [
                    ['ID' => 303, 'post_type' => 'product_variation', 'post_parent' => 201, 'post_status' => 'publish'],
                    ['ID' => 304, 'post_type' => 'product_variation', 'post_parent' => 201, 'post_status' => 'publish'],
                ],
                [
                    ['meta_key' => '_virtual', 'meta_value' => 'yes'],
                    ['meta_key' => '_downloadable', 'meta_value' => 'no'],
                    ['meta_key' => '_regular_price', 'meta_value' => '30.00'],
                ],
                [
                    ['meta_key' => '_virtual', 'meta_value' => 'no'],
                    ['meta_key' => '_downloadable', 'meta_value' => 'no'],
                    ['meta_key' => '_regular_price', 'meta_value' => '40.00'],
                ],
            );
        $wpDbProp = $reflection->getProperty('wpDb');
        $wpDbProp->setValue($this->command, $wpDbMock);

        $wpPrefixProp = $reflection->getProperty('wpPrefix');
        $wpPrefixProp->setValue($this->command, 'wp_');

        $this->setIo($reflection);

        $this->valeDbMock->method('insert')->willReturn(1);
        $this->valeDbMock->method('lastInsertId')->willReturn(500);
        $this->valeDbMock->method('fetchOne')->willReturn('0');

        $shippingUpdates = [];
        $this->valeDbMock->method('update')
            ->willReturnCallback(function ($table, $data, $identifier) use (&$shippingUpdates) {
                if (isset($data['shipping_class'])) {
                    $shippingUpdates[] = [$identifier['id'] => $data['shipping_class']];
                }
                return 1;
            });

        $method = $reflection->getMethod('importWooVariations');
        $method->invoke($this->command, 200, 20);
        $method->invoke($this->command, 201, 21);

        $this->assertSame([[20 => 'digital']], $shippingUpdates);
    }

    public function testPageImportSkipReasonFlagsWooSystemPages(): void
    {
        $reflection = new \ReflectionClass(ImportWordPressCommand::class);
        $method     = $reflection->getMethod('pageImportSkipReason');

        foreach (['cart', 'checkout', 'my-account', 'shop'] as $slug) {
            $reason = $method->invoke($this->command, $slug, 'Title');
            $this->assertNotNull($reason, $slug);
            $this->assertStringContainsString('WooCommerce', (string) $reason);
        }
    }

    public function testPageImportSkipReasonFlagsRouteConflict(): void
    {
        $valeDb = $this->createStub(Connection::class);
        $valeDb->method('fetchOne')->willReturn(false);
        $valeDb->method('fetchAllAssociative')->willReturn([]);
        $command = new ImportWordPressCommand($valeDb, $this->hasher);

        $previous        = $_ENV['APP_KEY'] ?? null;
        $_ENV['APP_KEY'] = str_repeat('a', 64);
        try {
            $reflection = new \ReflectionClass(ImportWordPressCommand::class);
            $method     = $reflection->getMethod('pageImportSkipReason');

            $reason = $method->invoke($command, 'blog', 'Blog');
            $this->assertNotNull($reason);
            $this->assertStringContainsString('/blog', (string) $reason);

            self::assertNull($method->invoke($command, 'my-safe-page', 'Safe'));
        } finally {
            if ($previous === null) {
                unset($_ENV['APP_KEY']);
            } else {
                $_ENV['APP_KEY'] = $previous;
            }
        }
    }

    public function testPageImportSkipReasonWithoutAppKeySkipsCheck(): void
    {
        // setUp already builds the command on a stubbed Connection: no
        // expectations needed since the validator can never be reached.
        $this->setIo($reflection = new \ReflectionClass(ImportWordPressCommand::class));

        $previous = $_ENV['APP_KEY'] ?? null;
        unset($_ENV['APP_KEY']);
        try {
            $method = $reflection->getMethod('pageImportSkipReason');

            // Validator cannot be built: guard degrades to null instead of crashing.
            self::assertNull($method->invoke($this->command, 'blog', 'Blog'));
        } finally {
            if ($previous !== null) {
                $_ENV['APP_KEY'] = $previous;
            }
        }
    }

    public function testCopyImageSizeVariantsCopiesSiblingsOnly(): void
    {
        $base = sys_get_temp_dir() . '/vale-variant-test-' . uniqid();
        $src  = $base . '/src';
        $dst  = $base . '/dst';
        mkdir($src, 0755, true);
        mkdir($dst, 0755, true);
        try {
            file_put_contents($src . '/photo.png', 'orig');
            file_put_contents($src . '/photo-300x200.png', 'small');
            file_put_contents($src . '/photo-scaled.png', 'scaled');
            file_put_contents($src . '/photo-300x200.jpg', 'wrong-ext');
            file_put_contents($src . '/other.txt', 'text');

            $reflection = new \ReflectionClass(ImportWordPressCommand::class);
            $method     = $reflection->getMethod('copyImageSizeVariants');
            $method->invoke($this->command, $src . '/photo.png', $base, 'dst/photo.png');

            self::assertFileExists($dst . '/photo-300x200.png');
            self::assertFileExists($dst . '/photo-scaled.png');
            self::assertFileDoesNotExist($dst . '/photo-300x200.jpg');
            self::assertFileDoesNotExist($dst . '/other.txt');

            $prop = $reflection->getProperty('copiedVariants');
            self::assertSame(2, $prop->getValue($this->command));
        } finally {
            array_map('unlink', glob($src . '/*') ?: []);
            array_map('unlink', glob($dst . '/*') ?: []);
            rmdir($src);
            rmdir($dst);
            rmdir($base);
        }
    }

    public function testSanitizeUploadRelativePathBlocksEscapesAndExecutables(): void
    {
        $reflection = new \ReflectionClass(ImportWordPressCommand::class);
        $method     = $reflection->getMethod('sanitizeUploadRelativePath');

        $sanitize = fn (string $path): ?string => $method->invoke(
            $this->command,
            $path,
            'https://example.com/wp-content/uploads/' . $path,
        );

        // Legit WordPress layout survives (leading slash and "." segments dropped).
        self::assertSame('2025/08/photo.jpg', $sanitize('2025/08/photo.jpg'));
        self::assertSame('2025/08/photo.jpg', $sanitize('/2025/08/photo.jpg'));
        self::assertSame('photo.jpg', $sanitize('./photo.jpg'));
        self::assertSame('2025/08/photo.jpg', $sanitize('2025//08/photo.jpg'));

        // A URL path must never escape storage/uploads...
        self::assertNull($sanitize('../config/app.php'));
        self::assertNull($sanitize('2025/../../public/index.php'));
        self::assertNull($sanitize('..\\..\\public\\index.php'));

        // ...nor store anything from the executable blocklist.
        self::assertNull($sanitize('shell.php'));
        self::assertNull($sanitize('2025/08/shell.phtml'));
        self::assertNull($sanitize('.htaccess'));

        // Nothing left to store.
        self::assertNull($sanitize(''));
        self::assertNull($sanitize('/'));

        // Refusals are collected for the end-of-run report.
        $skipped = $reflection->getProperty('skippedMediaDownloads');
        self::assertNotEmpty($skipped->getValue($this->command));
    }

    public function testUploadsRelativePathMapping(): void
    {
        $reflection = new \ReflectionClass(ImportWordPressCommand::class);
        $method     = $reflection->getMethod('uploadsRelativePath');

        self::assertSame(
            '2025/08/a-1024x490.png',
            $method->invoke($this->command, '/vale/uploads/2025/08/a-1024x490.png', '/vale/uploads/')
        );
        self::assertSame(
            'a.png',
            $method->invoke($this->command, '/uploads/a.png?ver=1', '/uploads/')
        );
        self::assertNull($method->invoke($this->command, 'https://x.test/uploads/a.png', '/uploads/'));
        self::assertNull($method->invoke($this->command, '/other/a.png', '/uploads/'));
        self::assertNull($method->invoke($this->command, '/uploads/../a.png', '/uploads/'));
    }

    public function testCleanImportedTextDecodesEntities(): void
    {
        $reflection = new \ReflectionClass(ImportWordPressCommand::class);
        $method     = $reflection->getMethod('cleanImportedText');

        $clean = static fn (string $s): string => $method->invoke($reflection->newInstanceWithoutConstructor(), $s);

        self::assertSame('Branding & Personalization Options', $clean('Branding &amp; Personalization Options'));
        self::assertSame('Help & Support', $clean('Help &amp; Support'));
        self::assertSame('Fish & Chips', $clean('Fish &amp;amp; Chips'));
        self::assertSame("It's <b>bold</b>", $clean('It&#039;s &lt;b&gt;bold&lt;/b&gt;'));
        self::assertSame('a b', $clean("a\u{00A0}b"));
        self::assertSame('Plain Title', $clean('  Plain Title  '));
    }

    public function testMapMenuItemTargetMatrix(): void
    {
        $reflection = new \ReflectionClass(ImportWordPressCommand::class);
        foreach (['postMap' => [11 => 101], 'termIdMap' => [22 => 202], 'wooProductMap' => [33 => 303]] as $prop => $map) {
            $p = $reflection->getProperty($prop);
            $p->setValue($this->command, $map);
        }
        $this->setIo($reflection);

        $previousEnv    = $_ENV['APP_KEY']    ?? null;
        $previousServer = $_SERVER['APP_KEY'] ?? null;
        $previousGetenv = getenv('APP_KEY');
        unset($_ENV['APP_KEY'], $_SERVER['APP_KEY']);
        putenv('APP_KEY');
        try {
            $method = $reflection->getMethod('mapMenuItemTarget');

            // Invoke on the real command (these paths touch no DB).
            $call = function (array $ni) use ($method): ?array {
                return $method->invoke($this->command, $ni);
            };

            self::assertSame(
                ['type' => 'page', 'referenceId' => 101, 'url' => null],
                $call(['menu_type' => 'post_type', 'object' => 'page', 'object_id' => 11, 'object_slug' => 'about', 'url' => ''])
            );
            self::assertSame(
                ['type' => 'category', 'referenceId' => 202, 'url' => null],
                $call(['menu_type' => 'taxonomy', 'object' => 'category', 'object_id' => 22, 'object_slug' => 'news', 'url' => ''])
            );
            self::assertSame(
                ['type' => 'product', 'referenceId' => 303, 'url' => null],
                $call(['menu_type' => 'post_type', 'object' => 'product', 'object_id' => 33, 'object_slug' => 'snap', 'url' => ''])
            );
            self::assertSame(
                ['type' => 'custom', 'referenceId' => null, 'url' => 'https://example.com/x?a=b'],
                $call(['menu_type' => 'custom', 'object' => 'custom', 'object_id' => 0, 'object_slug' => '', 'url' => 'https://example.com/x?a=b'])
            );
            self::assertSame(
                ['type' => 'custom', 'referenceId' => null, 'url' => '/missing-page'],
                $call(['menu_type' => 'post_type', 'object' => 'page', 'object_id' => 999, 'object_slug' => 'missing-page', 'url' => ''])
            );
            self::assertNull(
                $call(['menu_type' => 'post_type', 'object' => 'page', 'object_id' => 999, 'object_slug' => '', 'url' => ''])
            );
            self::assertNull(
                $call(['menu_type' => 'weird', 'object' => 'weird', 'object_id' => 0, 'object_slug' => '', 'url' => ''])
            );
        } finally {
            if ($previousEnv !== null) {
                $_ENV['APP_KEY'] = $previousEnv;
            }
            if ($previousServer !== null) {
                $_SERVER['APP_KEY'] = $previousServer;
            }
            if ($previousGetenv !== false) {
                putenv('APP_KEY=' . $previousGetenv);
            }
        }
    }

    public function testWxrImportsCategoryNameAndSlugFromWpCategoryElement(): void
    {
        $this->useRecordingValeDb();
        $reflection = new \ReflectionClass(ImportWordPressCommand::class);
        $this->setIo($reflection);

        // wp:category carries cat_name/category_nicename — never term_name/term_slug.
        $category = new SimpleXMLElement(<<<XML
<wp:category xmlns:wp="http://wordpress.org/export/1.2/">
    <wp:term_id><![CDATA[8]]></wp:term_id>
    <wp:category_nicename><![CDATA[guides]]></wp:category_nicename>
    <wp:category_parent><![CDATA[]]></wp:category_parent>
    <wp:cat_name><![CDATA[Guides]]></wp:cat_name>
</wp:category>
XML);

        $inserted = [];
        $this->valeDbMock->method('fetchAssociative')->willReturn(false);
        $this->valeDbMock->method('insert')
            ->willReturnCallback(function (string $table, array $data) use (&$inserted): int {
                $inserted[] = ['table' => $table, 'data' => $data];
                return 1;
            });

        $method = $reflection->getMethod('wxrImportSingleTerm');
        $method->invoke($this->command, $category, 'category', new ProgressBar(new BufferedOutput()));

        self::assertSame([[
            'table' => 'vl_categories',
            'data'  => [
                'name'         => 'Guides',
                'slug'         => 'guides',
                'language'     => 'en',
                'created_at'   => $inserted[0]['data']['created_at'] ?? null,
                'content_type' => 'post',
            ],
        ]], $inserted);
    }

    public function testWxrResolvesTermParentsAfterEveryTermIsImported(): void
    {
        $this->useRecordingValeDb();
        $reflection = new \ReflectionClass(ImportWordPressCommand::class);
        $this->setIo($reflection);

        // Child first on purpose — WXR order does not guarantee parents first.
        $child = new SimpleXMLElement(<<<XML
<wp:term xmlns:wp="http://wordpress.org/export/1.2/">
    <wp:term_id><![CDATA[39]]></wp:term_id>
    <wp:term_taxonomy><![CDATA[product_cat]]></wp:term_taxonomy>
    <wp:term_slug><![CDATA[adapters]]></wp:term_slug>
    <wp:term_parent><![CDATA[accessories]]></wp:term_parent>
    <wp:term_name><![CDATA[Adapters]]></wp:term_name>
</wp:term>
XML);
        $parent = new SimpleXMLElement(<<<XML
<wp:term xmlns:wp="http://wordpress.org/export/1.2/">
    <wp:term_id><![CDATA[37]]></wp:term_id>
    <wp:term_taxonomy><![CDATA[product_cat]]></wp:term_taxonomy>
    <wp:term_slug><![CDATA[accessories]]></wp:term_slug>
    <wp:term_name><![CDATA[Accessories]]></wp:term_name>
</wp:term>
XML);
        $orphan = new SimpleXMLElement(<<<XML
<wp:term xmlns:wp="http://wordpress.org/export/1.2/">
    <wp:term_id><![CDATA[50]]></wp:term_id>
    <wp:term_taxonomy><![CDATA[product_cat]]></wp:term_taxonomy>
    <wp:term_slug><![CDATA[orphan]]></wp:term_slug>
    <wp:term_parent><![CDATA[missing-parent]]></wp:term_parent>
    <wp:term_name><![CDATA[Orphan]]></wp:term_name>
</wp:term>
XML);

        $autoId = 100;
        $this->valeDbMock->method('fetchAssociative')->willReturn(false);
        $this->valeDbMock->method('fetchOne')->willReturn(false);
        $this->valeDbMock->method('insert')->willReturn(1);
        $this->valeDbMock->method('lastInsertId')
            ->willReturnCallback(static function () use (&$autoId): int {
                return ++$autoId;
            });

        $parentUpdates = [];
        $this->valeDbMock->method('update')
            ->willReturnCallback(function (string $table, array $data, array $identifier) use (&$parentUpdates): int {
                if (isset($data['parent_id'])) {
                    $parentUpdates[] = ['table' => $table, 'parent_id' => $data['parent_id'], 'id' => $identifier['id']];
                }
                return 1;
            });

        $importTerm = $reflection->getMethod('wxrImportSingleTerm');
        $progress   = new ProgressBar(new BufferedOutput());
        $importTerm->invoke($this->command, $child, 'product_cat', $progress);   // vale id 101
        $importTerm->invoke($this->command, $parent, 'product_cat', $progress);  // vale id 102
        $importTerm->invoke($this->command, $orphan, 'product_cat', $progress);  // unresolvable parent

        $resolve = $reflection->getMethod('wxrResolveTermParents');
        $resolve->invoke($this->command);

        // Only the resolvable link is written: adapters (101) -> accessories (102).
        self::assertSame([
            ['table' => 'vl_product_categories', 'parent_id' => 102, 'id' => 101],
        ], $parentUpdates);
    }

    private function setIo(\ReflectionClass $reflection): void
    {
        $prop = $reflection->getProperty('io');
        $prop->setValue($this->command, $this->createStub(SymfonyStyle::class));
    }

    private function useMockValeDb(): void
    {
        $this->valeDbMock = $this->createMock(Connection::class);
        $this->command    = new ImportWordPressCommand($this->valeDbMock, $this->hasher);
    }

    /**
     * Like useMockValeDb() but stubbed: for tests that record invocations in
     * willReturnCallback() and assert on the recorded values afterwards.
     */
    private function useRecordingValeDb(): void
    {
        $this->valeDbMock = $this->createStub(Connection::class);
        $this->command    = new ImportWordPressCommand($this->valeDbMock, $this->hasher);
    }

    public function testPageCacheIsStoredWhenProvided(): void
    {
        $dir = sys_get_temp_dir() . '/vale-wp-wiring-' . uniqid();
        mkdir($dir, 0775, true);
        try {
            $cache   = new \ValeApp\Infrastructure\Cache\PageCache($dir);
            $command = new ImportWordPressCommand($this->createStub(Connection::class), $this->hasher, null, $cache);

            $prop = new \ReflectionProperty(ImportWordPressCommand::class, 'pageCache');

            $this->assertSame($cache, $prop->getValue($command));
        } finally {
            @rmdir($dir);
        }
    }
}
