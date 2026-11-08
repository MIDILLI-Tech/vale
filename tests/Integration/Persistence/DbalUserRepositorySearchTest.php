<?php

declare(strict_types=1);

namespace ValeApp\Tests\Integration\Persistence;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\DriverManager;
use Phinx\Config\Config;
use Phinx\Migration\Manager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\User\Entity\User;
use ValeApp\Domain\User\Repository\UserRepositoryInterface;
use ValeApp\Infrastructure\Persistence\DbalUserRepository;
use ValeApp\Infrastructure\Security\Encrypter;

/**
 * The admin users listing shows name, email, role and status. Search must
 * cover the role column (badge) as well — client-side mode always matched it
 * via the rendered text, so server-side results are kept identical.
 */
final class DbalUserRepositorySearchTest extends TestCase
{
    private static DbalConnection $db;

    public static function setUpBeforeClass(): void
    {
        $dbFile = sys_get_temp_dir() . '/vl_usersearch_' . bin2hex(random_bytes(8)) . '.sqlite';

        $_ENV['DB_DRIVER'] = 'sqlite';
        $_ENV['DB_NAME']   = $dbFile;
        putenv('DB_DRIVER=sqlite');
        putenv('DB_NAME=' . $dbFile);

        if (!defined('VL_DB_PREFIX')) {
            define('VL_DB_PREFIX', 'vl_');
        }

        self::$db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $config                        = Config::fromPhp(dirname(__DIR__, 3) . '/phinx.php');
        $envs                          = $config['environments'];
        $envs['default']['connection'] = self::$db->getNativeConnection();
        $config['environments']        = $envs;

        $manager = new Manager($config, new ArrayInput([]), new NullOutput());
        $manager->migrate('default');
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$db)) {
            try {
                self::$db->close();
            } catch (\Throwable) {
            }
        }
    }

    protected function setUp(): void
    {
        self::$db->beginTransaction();
    }

    protected function tearDown(): void
    {
        try {
            self::$db->rollBack();
        } catch (\Throwable) {
        }
    }

    private function repo(): UserRepositoryInterface
    {
        return new DbalUserRepository(self::$db, new Encrypter(str_repeat('a', 64)));
    }

    private function insertRole(string $slug, string $name): int
    {
        self::$db->insert(VL_DB_PREFIX . 'roles', ['slug' => $slug, 'name' => $name]);

        return (int) self::$db->lastInsertId();
    }

    private function insertUser(string $email, string $name, ?int $roleId = null): int
    {
        self::$db->insert(VL_DB_PREFIX . 'users', [
            'email'    => $email,
            'password' => 'hashed',
            'name'     => $name,
        ]);
        $id = (int) self::$db->lastInsertId();
        if ($roleId !== null) {
            self::$db->insert(VL_DB_PREFIX . 'user_roles', ['user_id' => $id, 'role_id' => $roleId]);
        }

        return $id;
    }

    public function testSearchMatchesRoleSlug(): void
    {
        $admin = $this->insertRole('admin', 'Administrators');
        $this->insertUser('boss@example.com', 'Patron', $admin);
        $this->insertUser('reader@example.com', 'Okuyucu');

        self::assertSame(1, $this->repo()->count(['search' => 'admin']));
        $users = $this->repo()->findAll(new Pagination(1, 50), ['search' => 'admin']);
        self::assertCount(1, $users);
        self::assertSame('Patron', $users[0]->getName());
    }

    public function testSearchMatchesRoleDisplayName(): void
    {
        $editor = $this->insertRole('editor', 'Editors Team');
        $this->insertUser('writer@example.com', 'Yazar', $editor);
        $this->insertUser('other@example.com', 'Diger');

        self::assertSame(1, $this->repo()->count(['search' => 'editors team']));
    }

    public function testSearchStillMatchesNameAndEmail(): void
    {
        $this->insertUser('kamelya@example.com', 'Kamelya Yılmaz');
        $this->insertUser('other@example.com', 'Başkası');

        self::assertSame(1, $this->repo()->count(['search' => 'kamelya']));
    }

    public function testSearchCombinesWithRoleFilter(): void
    {
        $admin   = $this->insertRole('admin', 'Administrators');
        $editor  = $this->insertRole('editor', 'Editors Team');
        $this->insertUser('admin-writer@example.com', 'Yazar Bir', $admin);
        $this->insertUser('editor-writer@example.com', 'Yazar Iki', $editor);

        self::assertSame(1, $this->repo()->count(['search' => 'yazar', 'role' => 'editor']));
    }

    public function testSearchWithoutMatchesReturnsEmpty(): void
    {
        $admin = $this->insertRole('admin', 'Administrators');
        $this->insertUser('boss@example.com', 'Patron', $admin);

        self::assertSame(0, $this->repo()->count(['search' => 'inception']));
        self::assertSame([], $this->repo()->findAll(new Pagination(1, 50), ['search' => 'inception']));
    }

    public function testEmptySearchKeepsPreviousBehaviour(): void
    {
        $admin = $this->insertRole('admin', 'Administrators');
        $this->insertUser('boss@example.com', 'Patron', $admin);
        $this->insertUser('reader@example.com', 'Okuyucu');

        self::assertSame(2, $this->repo()->count());
        self::assertCount(2, $this->repo()->findAll(new Pagination(1, 50)));
    }

    public function testFindAllSortsByRoleAscending(): void
    {
        $admin    = $this->insertRole('admin', 'Administrators');
        $editor   = $this->insertRole('editor', 'Editors Team');
        $this->insertUser('z-user@example.com', 'Z Kullanici', $admin);
        $this->insertUser('a-user@example.com', 'A Kullanici', $editor);
        $this->insertUser('no-role@example.com', 'Rolsuz');

        $users = $this->repo()->findAll(new Pagination(1, 50, 'role', 'asc'));

        self::assertCount(3, $users);
        self::assertSame('Rolsuz', $users[0]->getName());
        self::assertSame('Z Kullanici', $users[1]->getName());
        self::assertSame('A Kullanici', $users[2]->getName());
    }

    public function testFindAllSortsByRoleDescending(): void
    {
        $admin  = $this->insertRole('admin', 'Administrators');
        $editor = $this->insertRole('editor', 'Editors Team');
        $this->insertUser('z-user@example.com', 'Z Kullanici', $admin);
        $this->insertUser('a-user@example.com', 'A Kullanici', $editor);

        $users = $this->repo()->findAll(new Pagination(1, 50, 'role', 'desc'));

        self::assertSame(
            ['A Kullanici', 'Z Kullanici'],
            array_map(fn (User $user) => $user->getName(), $users),
        );
    }

    public function testFindAllStillSortsByNameAndEmail(): void
    {
        $this->insertUser('bbb@example.com', 'Zeynep');
        $this->insertUser('aaa@example.com', 'Ahmet');

        $byName = $this->repo()->findAll(new Pagination(1, 50, 'name', 'asc'));
        self::assertSame(
            ['Ahmet', 'Zeynep'],
            array_map(fn (User $user) => $user->getName(), $byName),
        );

        $byEmail = $this->repo()->findAll(new Pagination(1, 50, 'email', 'asc'));
        self::assertSame(
            ['Ahmet', 'Zeynep'],
            array_map(fn (User $user) => $user->getName(), $byEmail),
        );
    }
}
