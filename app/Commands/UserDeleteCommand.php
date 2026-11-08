<?php

declare(strict_types=1);

namespace ValeApp\App\Commands;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use ValeApp\Infrastructure\Auth\PasswordHasher;

final class UserDeleteCommand extends Command
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
            ->setName('user:delete')
            ->setDescription('Delete a user by email')
            ->addOption('email', null, InputOption::VALUE_OPTIONAL, 'User email address')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Force delete without confirmation')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would happen without deleting anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = $input->getOption('email');
        if (!$email) {
            $question = new Question('Email: ');
            $email    = $this->getHelper('question')->ask($input, $output, $question);
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

        $force  = $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');

        if (!$force && !$dryRun) {
            $confirmQuestion = new ConfirmationQuestion(
                sprintf(
                    'Delete user "%s" (%s)? [y/N]: ',
                    $user['name'],
                    $user['email']
                ),
                false
            );
            $confirmed = $this->getHelper('question')->ask($input, $output, $confirmQuestion);

            if (!$confirmed) {
                $output->writeln('<info>Operation cancelled.</info>');
                return Command::SUCCESS;
            }
        }

        if ($dryRun) {
            $action = $force ? 'permanently deleted' : 'anonymized (soft-deleted)';
            $output->writeln(sprintf(
                "<comment>Dry run: user '%s' (%s) would be %s. No changes were made.</comment>",
                $user['name'],
                $user['email'],
                $action
            ));
            return Command::SUCCESS;
        }

        if ($force) {
            $this->connection->delete(VL_DB_PREFIX . 'user_roles', ['user_id' => $user['id']]);
            $this->connection->delete(VL_DB_PREFIX . 'users', ['id' => $user['id']]);
            $output->writeln("<info>User '{$user['name']}' permanently deleted.</info>");
        } else {
            $anonymizedEmail = 'deleted-' . $user['id'] . '-' . bin2hex(random_bytes(8)) . '@anonymized.invalid';

            $this->connection->update(VL_DB_PREFIX . 'users', [
                'name'                          => 'Deleted User',
                'email'                         => $anonymizedEmail,
                'password'                      => bin2hex(random_bytes(32)),
                'avatar'                        => null,
                'is_active'                     => 0,
                'email_verified_at'             => null,
                'verification_token'            => null,
                'verification_token_expires_at' => null,
                'billing_address'               => null,
                'shipping_address'              => null,
                'updated_at'                    => date('Y-m-d H:i:s'),
            ], ['id' => $user['id']]);

            $this->connection->update(VL_DB_PREFIX . 'orders', [
                'user_id' => null,
            ], ['user_id' => $user['id']]);

            $output->writeln("<info>User '{$user['name']}' anonymized (soft-deleted).</info>");
        }

        return Command::SUCCESS;
    }
}
