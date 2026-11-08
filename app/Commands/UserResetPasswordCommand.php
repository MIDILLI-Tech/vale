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

final class UserResetPasswordCommand extends Command
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
            ->setName('user:reset-password')
            ->setDescription('Reset a user\'s password')
            ->addOption('email', null, InputOption::VALUE_OPTIONAL, 'User email address')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate without changing the password');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $helper = $this->getHelper('question');

        $email = $input->getOption('email');
        if (!$email) {
            $email = $helper->ask($input, $output, new Question('Email: '));
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

        $passQ = new Question('New password: ');
        $passQ->setHidden(true);
        $passQ->setHiddenFallback(false);
        $password = $helper->ask($input, $output, $passQ);

        $confirmQ = new Question('Confirm password: ');
        $confirmQ->setHidden(true);
        $confirmQ->setHiddenFallback(false);
        $confirm = $helper->ask($input, $output, $confirmQ);

        if (!$password || strlen($password) < 8) {
            $output->writeln('<error>Password must be at least 8 characters.</error>');
            return Command::FAILURE;
        }

        if ($password !== $confirm) {
            $output->writeln('<error>Passwords do not match.</error>');
            return Command::FAILURE;
        }

        if ((bool) $input->getOption('dry-run')) {
            $output->writeln(sprintf(
                "<comment>Dry run: password for '%s' (%s) is valid. "
                . 'Run without --dry-run to actually reset it.</comment>',
                $user['name'],
                $user['email']
            ));
            return Command::SUCCESS;
        }

        $hash = $this->hasher->hash($password);

        $this->connection->update(VL_DB_PREFIX . 'users', [
            'password'   => $hash,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $user['id']]);

        $output->writeln("<info>Password reset successfully for '{$user['name']}' ({$user['email']}).</info>");

        return Command::SUCCESS;
    }
}
