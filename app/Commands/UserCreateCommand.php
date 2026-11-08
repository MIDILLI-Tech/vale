<?php

declare(strict_types=1);

namespace ValeApp\App\Commands;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use ValeApp\Infrastructure\Auth\PasswordHasher;

/**
 * Create a new user from CLI.
 *
 * Usage:  php vale user:create
 */
final class UserCreateCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly PasswordHasher $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('user:create')
            ->setDescription('Create a new user')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'User name')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'User email')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'User password')
            ->addOption('role', null, InputOption::VALUE_OPTIONAL, 'User role (super_admin/admin/editor/author/subscriber)', 'admin')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview the new user without writing anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name     = $input->getOption('name');
        $email    = $input->getOption('email');
        $password = $input->getOption('password');
        $roleName = $input->getOption('role');

        if (!$name || !$email || !$password) {
            $output->writeln('<comment>Interactive mode — press Enter or Ctrl+C to cancel.</comment>');
        }

        if (!$name) {
            $name = $this->getHelper('question')->ask($input, $output, new Question('Name: '));
        }

        if (!$email) {
            $email = $this->getHelper('question')->ask($input, $output, new Question('Email: '));
        }

        if (!$password) {
            $password = $this->getHelper('question')->ask($input, $output, new Question('Password: '));
        }

        if (!$name || !$email || !$password) {
            $output->writeln('<error>Name, email and password are required. Use --name, --email, --password options or answer the prompts above.</error>');
            $output->writeln('<info>Example: php vale user:create --name="John Doe" --email=john@example.com --password=secret</info>');
            return Command::FAILURE;
        }

        // Hash password
        $hash = $this->hasher->hash($password);

        // Get role ID
        $role = $this->connection->fetchAssociative(
            'SELECT id FROM ' . VL_DB_PREFIX . 'roles' . ' WHERE slug = ?',
            [$roleName],
        );

        if (!$role) {
            $output->writeln("<error>Role '{$roleName}' not found. Run migrations and seeders first.</error>");
            return Command::FAILURE;
        }

        $existing = $this->connection->fetchOne(
            'SELECT id FROM ' . VL_DB_PREFIX . 'users' . ' WHERE email = ?',
            [$email],
        );

        if ($existing) {
            $output->writeln("<error>Email '{$email}' already exists. Choose another email.</error>");
            return Command::FAILURE;
        }

        if ((bool) $input->getOption('dry-run')) {
            $output->writeln('<comment>DRY RUN — no changes will be written.</comment>');
            $output->writeln(sprintf('  Name    : %s', $name));
            $output->writeln(sprintf('  Email   : %s', $email));
            $output->writeln(sprintf('  Role    : %s', $roleName));
            $output->writeln('  Active  : yes');
            $output->writeln('  Verified: yes');
            $output->writeln('<comment>Dry run complete. Run without --dry-run to actually create the user.</comment>');
            return Command::SUCCESS;
        }

        // Insert user
        $this->connection->insert(VL_DB_PREFIX . 'users', [
            'email'             => $email,
            'password'          => $hash,
            'name'              => $name,
            'locale'            => 'en',
            'is_active'         => 1,
            // CLI-created users are trusted by the administrator — mark the
            // email verified so the login gate does not lock them out.
            'email_verified_at' => date('Y-m-d H:i:s'),
            'created_at'        => date('Y-m-d H:i:s'),
            'updated_at'        => date('Y-m-d H:i:s'),
        ]);

        $userId = (int) $this->connection->lastInsertId();

        // Assign role
        $this->connection->insert(VL_DB_PREFIX . 'user_roles', [
            'user_id' => $userId,
            'role_id' => $role['id'],
        ]);

        $output->writeln("<info>User '{$name}' created with role '{$roleName}' (ID: {$userId}).</info>");

        return Command::SUCCESS;
    }
}
