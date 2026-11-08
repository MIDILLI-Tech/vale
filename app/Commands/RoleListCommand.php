<?php

declare(strict_types=1);

namespace ValeApp\App\Commands;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * List all roles with user and permission counts.
 *
 * Usage:  php vale role:list
 */
final class RoleListCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('role:list')
            ->setDescription('List all roles');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $roles = $this->connection->fetchAllAssociative(
            'SELECT r.id, r.slug, r.name, r.description, r.is_system,'
            . ' (SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'user_roles ur WHERE ur.role_id = r.id) AS users,'
            . ' (SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'role_permissions rp WHERE rp.role_id = r.id) AS permissions'
            . ' FROM ' . VL_DB_PREFIX . 'roles r ORDER BY r.id ASC',
        );

        if ($roles === []) {
            $output->writeln('<comment>No roles found. Run migrations and seeders first.</comment>');
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['ID', 'Slug', 'Name', 'System', 'Users', 'Permissions', 'Description']);

        foreach ($roles as $role) {
            $table->addRow([
                (int) $role['id'],
                $role['slug'],
                $role['name'],
                ((int) $role['is_system']) === 1 ? 'yes' : 'no',
                (int) $role['users'],
                (int) $role['permissions'],
                $role['description'] ?? '',
            ]);
        }

        $table->render();

        return Command::SUCCESS;
    }
}
