<?php

declare(strict_types=1);

namespace ValeApp\App\Commands;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

final class UserSetRoleCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('user:set-role')
            ->setDescription('Set a user\'s role')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'User email address')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'Role slug (e.g. super_admin, admin, editor, author, subscriber)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview without writing to the database');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = $input->getOption('email');
        if (!$email) {
            $email = $this->getHelper('question')->ask($input, $output, new Question('Email: '));
        }

        if (!$email) {
            $output->writeln('<error>Email is required.</error>');
            return Command::FAILURE;
        }

        $user = $this->connection->fetchAssociative(
            'SELECT id, name, email FROM ' . VL_DB_PREFIX . 'users' . ' WHERE email = ?',
            [$email],
        );

        if (!$user) {
            $output->writeln("<error>User with email '{$email}' not found.</error>");
            return Command::FAILURE;
        }

        $roleSlug = $input->getOption('role');
        if (!$roleSlug) {
            $roleSlug = $this->getHelper('question')->ask($input, $output, new Question('Role: '));
        }

        if (!$roleSlug) {
            $output->writeln('<error>Role is required.</error>');
            return Command::FAILURE;
        }

        $role = $this->connection->fetchAssociative(
            'SELECT id FROM ' . VL_DB_PREFIX . 'roles' . ' WHERE slug = ?',
            [$roleSlug],
        );

        if (!$role) {
            $output->writeln("<error>Role '{$roleSlug}' not found. Run migrations and seeders first.</error>");
            return Command::FAILURE;
        }

        if ((bool) $input->getOption('dry-run')) {
            $output->writeln(sprintf(
                "<comment>Dry run: role '%s' would be assigned to user '%s' (%s). "
                . 'Run without --dry-run to apply.</comment>',
                $roleSlug,
                $user['name'],
                $user['email']
            ));
            return Command::SUCCESS;
        }

        $userId = (int) $user['id'];
        $roleId = (int) $role['id'];

        $this->connection->delete(VL_DB_PREFIX . 'user_roles', ['user_id' => $userId]);

        $this->connection->insert(VL_DB_PREFIX . 'user_roles', [
            'user_id'    => $userId,
            'role_id'    => $roleId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->connection->update(VL_DB_PREFIX . 'users', [
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $userId]);

        $output->writeln("<info>Role '{$roleSlug}' assigned to user '{$user['name']}' ({$user['email']}).</info>");

        return Command::SUCCESS;
    }
}
