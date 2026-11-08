<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Commands;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use ValeApp\App\Commands\UserActivateCommand;
use ValeApp\App\Commands\UserCreateCommand;
use ValeApp\App\Commands\UserDeleteCommand;
use ValeApp\App\Commands\UserSetRoleCommand;
use ValeApp\Infrastructure\Auth\PasswordHasher;

final class UserCommandsDryRunTest extends TestCase
{
    public function testCreateDryRunDoesNotInsert(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn(['id' => 5]);
        $db->expects($this->never())->method('insert');
        $db->expects($this->never())->method('lastInsertId');

        $command  = new UserCreateCommand($db, new PasswordHasher());
        $input    = new ArrayInput([
            '--name'     => 'John Doe',
            '--email'    => 'john@example.com',
            '--password' => 'secret1234',
            '--dry-run'  => true,
        ]);
        $output   = new BufferedOutput();
        $exitCode = $command->run($input, $output);

        self::assertSame(0, $exitCode);
        $text = $output->fetch();
        self::assertStringContainsString('DRY RUN', $text);
        self::assertStringContainsString('john@example.com', $text);
        self::assertStringContainsString('Dry run complete', $text);
    }

    public function testDeleteDryRunSkipsConfirmationAndAnonymize(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn([
            'id'    => 3,
            'name'  => 'Jane Doe',
            'email' => 'jane@example.com',
        ]);
        $db->expects($this->never())->method('update');
        $db->expects($this->never())->method('delete');

        $command  = new UserDeleteCommand($db, new PasswordHasher());
        $input    = new ArrayInput([
            '--email'   => 'jane@example.com',
            '--dry-run' => true,
        ]);
        $output   = new BufferedOutput();
        $exitCode = $command->run($input, $output);

        self::assertSame(0, $exitCode);
        $text = $output->fetch();
        self::assertStringContainsString('would be anonymized (soft-deleted)', $text);
        self::assertStringContainsString('No changes were made', $text);
    }

    public function testDeleteDryRunWithForceReportsHardDelete(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn([
            'id'    => 3,
            'name'  => 'Jane Doe',
            'email' => 'jane@example.com',
        ]);
        $db->expects($this->never())->method('delete');

        $command  = new UserDeleteCommand($db, new PasswordHasher());
        $input    = new ArrayInput([
            '--email'   => 'jane@example.com',
            '--force'   => true,
            '--dry-run' => true,
        ]);
        $output   = new BufferedOutput();
        $exitCode = $command->run($input, $output);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('would be permanently deleted', $output->fetch());
    }

    public function testActivateDryRunDoesNotUpdate(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn([
            'id'        => 7,
            'name'      => 'Bob',
            'email'     => 'bob@example.com',
            'is_active' => 1,
        ]);
        $db->expects($this->never())->method('update');

        $command  = new UserActivateCommand($db);
        $input    = new ArrayInput([
            '--email'      => 'bob@example.com',
            '--deactivate' => true,
            '--dry-run'    => true,
        ]);
        $output   = new BufferedOutput();
        $exitCode = $command->run($input, $output);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('would be deactivated', $output->fetch());
    }

    public function testSetRoleDryRunDoesNotWrite(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturnCallback(
            static fn (string $sql): array => str_contains($sql, 'roles')
                ? ['id' => 2]
                : ['id' => 3, 'name' => 'Bob', 'email' => 'bob@example.com'],
        );
        $db->expects($this->never())->method('delete');
        $db->expects($this->never())->method('insert');
        $db->expects($this->never())->method('update');

        $command  = new UserSetRoleCommand($db);
        $input    = new ArrayInput([
            '--email'   => 'bob@example.com',
            '--role'    => 'editor',
            '--dry-run' => true,
        ]);
        $output   = new BufferedOutput();
        $exitCode = $command->run($input, $output);

        self::assertSame(0, $exitCode);
        $text = $output->fetch();
        self::assertStringContainsString("role 'editor' would be assigned", $text);
        self::assertStringContainsString('Run without --dry-run to apply', $text);
    }
}
