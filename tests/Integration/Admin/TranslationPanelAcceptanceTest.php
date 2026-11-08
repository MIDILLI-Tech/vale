<?php

declare(strict_types=1);

namespace ValeApp\Tests\Integration\Admin;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\RequestFactory;

/**
 * The edit modal's translation panel is fed by the `translationsMap` payload the
 * index pages embed next to the Alpine component (the panel resolves the group
 * with `translationsMap[translationOf || id]`). These tests drive the real index
 * routes and assert the served payload carries exactly the right translation
 * group for each record — the original plus its translations, no other group's
 * rows, and translations reachable only through their root key.
 */
#[CoversNothing]
final class TranslationPanelAcceptanceTest extends TestCase
{
    private App $app;
    private static string $testDbPath;
    private static DbalConnection $db;

    public static function setUpBeforeClass(): void
    {
        self::$testDbPath = sys_get_temp_dir() . '/vltp_' . bin2hex(random_bytes(8)) . '.sqlite';

        $_ENV['APP_KEY']   = bin2hex(random_bytes(32));
        $_ENV['DB_DRIVER'] = 'sqlite';
        $_ENV['DB_NAME']   = self::$testDbPath;

        putenv('APP_KEY=' . $_ENV['APP_KEY']);
        putenv('DB_DRIVER=sqlite');
        putenv('DB_NAME=' . self::$testDbPath);

        $phinxApp = new \Phinx\Console\PhinxApplication();
        $wrapper  = new \Phinx\Wrapper\TextWrapper($phinxApp, [
            'configuration' => dirname(__DIR__, 3) . '/phinx.php',
            'environment'   => 'default',
        ]);
        $wrapper->getMigrate();
        $wrapper->getSeed();

        if (!defined('VL_DB_PREFIX')) {
            define('VL_DB_PREFIX', 'vl_');
        }

        self::$db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => self::$testDbPath]);

        // The panel payload is only built when multi-language content is on.
        self::setSetting('multi_language_content', '1');
    }

    public static function tearDownAfterClass(): void
    {
        try {
            self::$db->close();
        } catch (\Throwable) {
        }

        gc_collect_cycles();
        clearstatcache();

        @unlink(self::$testDbPath);
        @unlink(self::$testDbPath . '-wal');
        @unlink(self::$testDbPath . '-shm');
    }

    private static function setSetting(string $key, string $value): void
    {
        $id = self::$db->fetchOne(
            'SELECT id FROM ' . VL_DB_PREFIX . 'settings WHERE ' . self::$db->quoteIdentifier('key') . ' = ?',
            [$key]
        );

        if ($id !== false) {
            self::$db->update(VL_DB_PREFIX . 'settings', ['value' => $value], ['id' => (int) $id]);
            return;
        }

        self::$db->insert(VL_DB_PREFIX . 'settings', [
            'group_name'  => 'general',
            'key'         => $key,
            'value'       => $value,
            'type'        => 'bool',
            'is_autoload' => 1,
        ]);
    }

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $_SESSION['user_id']          = 1;
        $_SESSION['user_role']        = 'super_admin';
        $_SESSION['user']             = ['id' => 1, 'email' => 'admin@getvale.org', 'role' => 'super_admin'];
        $_SESSION['user_permissions'] = ['*'];
        $_SESSION['_csrf_tokens']     = 'translation-panel-acceptance-token';
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        /** @var App $app */
        $app       = require dirname(__DIR__, 3) . '/bootstrap/app.php';
        $this->app = $app;
    }

    private function page(string $path): string
    {
        $response = $this->app->handle((new RequestFactory())->createRequest('GET', $path));
        self::assertSame(200, $response->getStatusCode(), $path . ' should render.');

        return (string) $response->getBody();
    }

    /**
     * Pulls the `translationsMap` payload out of the Alpine `x-data` attribute.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function translationsMap(string $html): array
    {
        preg_match_all('/x-data="([^"]*)"/s', $html, $matches);
        $attribute = null;
        foreach ($matches[1] as $candidate) {
            if (str_contains($candidate, 'translationsMap:')) {
                $attribute = html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5);
                break;
            }
        }

        self::assertNotNull($attribute, 'The panel payload should be embedded in the page.');

        $pos = strpos($attribute, 'translationsMap:');
        self::assertNotFalse($pos);

        /** @var array<int, array<int, array<string, mixed>>> $map */
        $map = json_decode($this->jsonAfter($attribute, $pos + strlen('translationsMap:')), true, 512, JSON_THROW_ON_ERROR);

        return $map;
    }

    /** Returns the first JSON object/array that starts after the given offset. */
    private function jsonAfter(string $haystack, int $offset): string
    {
        $start = null;
        for ($i = $offset, $len = strlen($haystack); $i < $len; $i++) {
            if ($haystack[$i] === '{' || $haystack[$i] === '[') {
                $start = $i;
                break;
            }
        }
        self::assertNotNull($start, 'No JSON payload found after the marker.');

        $open     = $haystack[$start];
        $close    = $open === '{' ? '}' : ']';
        $depth    = 0;
        $inString = false;
        $escaped  = false;

        for ($i = $start, $len = strlen($haystack); $i < $len; $i++) {
            $char = $haystack[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === $open) {
                $depth++;
            } elseif ($char === $close) {
                $depth--;
                if ($depth === 0) {
                    return substr($haystack, $start, $i - $start + 1);
                }
            }
        }

        self::fail('Unbalanced JSON payload.');
    }

    /**
     * @param array<int, array<int, array<string, mixed>>> $map
     * @param string[]                                     $expectedFields   The fields the panel's openEdit() reads
     * @param int[]                                        $expectedIds
     * @param string[]                                     $expectedLanguages
     * @param int[]                                        $foreignIds
     */
    private function assertGroup(
        array $map,
        int $rootId,
        array $expectedFields,
        array $expectedIds,
        array $expectedLanguages,
        string $nameToken,
        array $foreignIds = [],
    ): void {
        self::assertArrayHasKey($rootId, $map, 'The root record should be a key of the payload.');

        $rows = $map[$rootId];
        self::assertSame($expectedIds, array_column($rows, 'id'));
        self::assertSame($expectedLanguages, array_column($rows, 'language'));

        foreach ($rows as $row) {
            self::assertSame($expectedFields, array_keys($row), 'The panel consumes exactly these row fields.');
            self::assertStringContainsString($nameToken, (string) $row['name']);
        }

        self::assertNull($rows[0]['translationOf'], 'The original points at no other record.');
        foreach (array_slice($rows, 1) as $row) {
            self::assertSame($rootId, $row['translationOf'], 'Translations must reference the original.');
        }

        foreach ($foreignIds as $foreignId) {
            self::assertNotContains($foreignId, array_column($rows, 'id'), 'Another group must not leak into this one.');
        }
    }

    private function insertRow(string $table, array $fields): int
    {
        self::$db->insert(VL_DB_PREFIX . $table, $fields);

        return (int) self::$db->lastInsertId();
    }

    public function testTagPanelPayloadCarriesTheFullTranslationGroup(): void
    {
        $token = 'paneltag' . bin2hex(random_bytes(3));

        $root = $this->insertRow('tags', [
            'name'         => $token . ' Original', 'slug' => $token . '-original',
            'content_type' => 'post', 'language' => 'en',
        ]);
        $fr = $this->insertRow('tags', [
            'name'         => $token . ' Fransizca', 'slug' => $token . '-fr',
            'content_type' => 'post', 'language' => 'fr', 'translation_of' => $root,
        ]);
        $tr = $this->insertRow('tags', [
            'name'         => $token . ' Turkce', 'slug' => $token . '-tr',
            'content_type' => 'post', 'language' => 'tr', 'translation_of' => $root,
        ]);
        $other = $this->insertRow('tags', [
            'name'         => $token . ' Other', 'slug' => $token . '-other',
            'content_type' => 'post', 'language' => 'en',
        ]);

        $html = $this->page('/admin/tags?content_type=post&search=' . $token);
        self::assertStringContainsString('editTranslations', $html, 'The page should render the translation panel.');

        $map = $this->translationsMap($html);

        self::assertSame(
            [$root, $other],
            array_values(array_map('intval', array_keys($map))),
            'Only the listed records\' groups belong in the payload.'
        );
        $tagFields = ['id', 'name', 'slug', 'language', 'translationOf', 'contentType'];
        $this->assertGroup($map, $root, $tagFields, [$root, $fr, $tr], ['en', 'fr', 'tr'], $token, [$other]);
        $this->assertGroup($map, $other, $tagFields, [$other], ['en'], $token, [$root, $fr, $tr]);

        self::assertArrayNotHasKey(
            $tr,
            $map,
            'A translation is reachable only through its root key — the panel resolves rootId = translationOf || id.'
        );
    }

    public function testCategoryPanelPayloadCarriesTheFullTranslationGroup(): void
    {
        $token = 'panelcat' . bin2hex(random_bytes(3));

        $root = $this->insertRow('categories', [
            'name'         => $token . ' Original', 'slug' => $token . '-original',
            'content_type' => 'post', 'language' => 'en',
        ]);
        $tr = $this->insertRow('categories', [
            'name'         => $token . ' Turkce', 'slug' => $token . '-tr',
            'content_type' => 'post', 'language' => 'tr', 'translation_of' => $root,
        ]);

        $map = $this->translationsMap($this->page('/admin/categories?content_type=post'));

        $categoryFields = ['id', 'name', 'slug', 'description', 'language', 'translationOf', 'parentId', 'sortOrder', 'contentType'];
        $this->assertGroup($map, $root, $categoryFields, [$root, $tr], ['en', 'tr'], $token);
        self::assertArrayNotHasKey($tr, $map);
    }

    public function testProductCategoryPanelPayloadCarriesTheFullTranslationGroup(): void
    {
        $token = 'panelpcat' . bin2hex(random_bytes(3));

        $root = $this->insertRow('product_categories', [
            'name' => $token . ' Original', 'slug' => $token . '-original', 'language' => 'en',
        ]);
        $tr = $this->insertRow('product_categories', [
            'name'     => $token . ' Turkce', 'slug' => $token . '-tr',
            'language' => 'tr', 'translation_of' => $root,
        ]);

        $map = $this->translationsMap($this->page('/admin/product-categories'));

        $productCategoryFields = ['id', 'name', 'slug', 'parentId', 'description', 'language', 'translationOf'];
        $this->assertGroup($map, $root, $productCategoryFields, [$root, $tr], ['en', 'tr'], $token);
        self::assertArrayNotHasKey($tr, $map);
    }
}
