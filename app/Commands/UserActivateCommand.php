<?php

declare(strict_types=1);

namespace ValeApp\App\Commands;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

final class UserActivateCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('user:activate')
            ->setDescription('Activate or deactivate a user')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'User email address')
            ->addOption('deactivate', null, InputOption::VALUE_NONE, 'Deactivate instead of activate')
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
            'SELECT id, name, email, is_active, email_verified_at FROM ' . VL_DB_PREFIX . 'users' . ' WHERE email = ?',
            [$email],
        );

        if (!$user) {
            $output->writeln("<error>User with email '{$email}' not found.</error>");
            return Command::FAILURE;
        }

        $deactivate = $input->getOption('deactivate');
        $newStatus  = $deactivate ? 0 : 1;
        $action     = $deactivate ? 'deactivated' : 'activated';

        if ((bool) $input->getOption('dry-run')) {
            $output->writeln(sprintf(
                "<comment>Dry run: user '%s' (%s) would be %s. Run without --dry-run to apply.</comment>",
                $user['name'],
                $user['email'],
                $action
            ));
            return Command::SUCCESS;
        }

        $data = [
            'is_active'  => $newStatus,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if (!$deactivate && ($user['email_verified_at'] ?? null) === null) {
            $data['email_verified_at'] = date('Y-m-d H:i:s');
        }

        $this->connection->update(VL_DB_PREFIX . 'users', $data, ['id' => $user['id']]);

        $output->writeln("<info>User '{$user['name']}' ({$user['email']}) {$action} successfully.</info>");

        return Command::SUCCESS;
    }
}
