<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Commands;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use ValeApp\App\Commands\ImportGhostCommand;
use ValeApp\Infrastructure\Auth\PasswordHasher;

final class ImportGhostCommandTest extends TestCase
{
    private function makeCommand(Connection $dbMock): ImportGhostCommand
    {
        $command = new ImportGhostCommand($dbMock, new PasswordHasher());

        // The importer writes progress to $this->io; provide a real style object.
        $reflection = new \ReflectionClass(ImportGhostCommand::class);
        $ioProp     = $reflection->getProperty('io');
        $ioProp->setValue($command, new SymfonyStyle(new ArrayInput([]), new BufferedOutput()));

        return $command;
    }

    private function invoke(string $method, ImportGhostCommand $command, mixed ...$args): mixed
    {
        $reflection = new \ReflectionClass(ImportGhostCommand::class);
        $m          = $reflection->getMethod($method);
        return $m->invoke($command, ...$args);
    }

    private function readProperty(string $prop, ImportGhostCommand $command): mixed
    {
        $reflection = new \ReflectionClass(ImportGhostCommand::class);
        $p          = $reflection->getProperty($prop);
        return $p->getValue($command);
    }

    public function testImportUsersMapsNewlyInsertedUserToRealId(): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('fetchAssociative')->willReturn(false);
        $db->method('lastInsertId')->willReturn('7');

        $command = $this->makeCommand($db);

        $this->invoke('importUsers', $command, [
            ['id' => 101, 'email' => 'author@example.com', 'name' => 'Ann', 'slug' => 'ann', 'created_at' => 1700000000000],
        ]);

        $userMap = $this->readProperty('userMap', $command);
        $this->assertSame([101 => 7], $userMap);
    }

    public function testImportUsersKeepsExistingRowIdWhenEmailMatches(): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('fetchAssociative')->willReturn(['id' => 42]);

        $command = $this->makeCommand($db);

        $this->invoke('importUsers', $command, [
            ['id' => 101, 'email' => 'author@example.com', 'name' => 'Ann', 'slug' => 'ann', 'created_at' => 1700000000000],
        ]);

        $userMap = $this->readProperty('userMap', $command);
        $this->assertSame([101 => 42], $userMap);
    }

    public function testImportTagsMapsNewlyInsertedTagToRealId(): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('fetchAssociative')->willReturn(false);
        $db->method('lastInsertId')->willReturn('12');

        $command = $this->makeCommand($db);

        $this->invoke('importTags', $command, [
            ['id' => 55, 'slug' => 'news', 'name' => 'News', 'created_at' => 1700000000000, 'updated_at' => 1700000000000],
        ]);

        $tagMap = $this->readProperty('tagMap', $command);
        $this->assertSame([55 => 12], $tagMap);
    }

    public function testImportPostsUsesMappedAuthorIdForNewlyImportedUser(): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('fetchAssociative')->willReturn(false);
        $db->method('lastInsertId')->willReturn('22');

        $inserted = null;
        $db->method('insert')->willReturnCallback(function (string $table, array $data) use (&$inserted): int {
            $inserted = $data;
            return 1;
        });

        $command = $this->makeCommand($db);

        // Simulate the state left behind by importUsers for a brand-new user.
        $reflection = new \ReflectionClass(ImportGhostCommand::class);
        $userMap    = $reflection->getProperty('userMap');
        $userMap->setValue($command, [101 => 7]);

        $posts = [[
            'id'               => 200,
            'type'             => 'post',
            'status'           => 'published',
            'title'            => 'Hello world',
            'slug'             => 'hello-world',
            'html'             => '<p>Hello</p>',
            'plaintext'        => 'Hello',
            'author_id'        => 101,
            'feature_image'    => null,
            'created_at'       => 1700000000000,
            'updated_at'       => 1700000000000,
            'published_at'     => 1700000000000,
            'custom_excerpt'   => null,
            'meta_title'       => null,
            'meta_description' => null,
        ]];

        $this->invoke('importPosts', $command, $posts, []);

        $this->assertIsArray($inserted);
        $this->assertSame(7, $inserted['author_id']);
        $this->assertSame('Hello world', $inserted['title']);
    }

    public function testPageCacheIsStoredWhenProvided(): void
    {
        $dir = sys_get_temp_dir() . '/vale-ghost-wiring-' . uniqid();
        mkdir($dir, 0775, true);
        try {
            $cache   = new \ValeApp\Infrastructure\Cache\PageCache($dir);
            $command = new ImportGhostCommand($this->createStub(Connection::class), new PasswordHasher(), null, $cache);

            $prop = new \ReflectionProperty(ImportGhostCommand::class, 'pageCache');

            $this->assertSame($cache, $prop->getValue($command));
        } finally {
            @rmdir($dir);
        }
    }
}
