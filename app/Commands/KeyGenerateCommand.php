<?php

declare(strict_types=1);

namespace ValeApp\App\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class KeyGenerateCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('key:generate')
            ->setDescription('Generate a new APP_KEY and list settings that must be re-entered');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $newKey = bin2hex(random_bytes(32));

        $output->writeln('');
        $output->writeln('<info>New APP_KEY generated:</info>');
        $output->writeln('');
        $output->writeln("  <comment>APP_KEY={$newKey}</comment>");
        $output->writeln('');
        $output->writeln('<error>WARNING: Changing APP_KEY will break all encrypted settings.</error>');
        $output->writeln('');
        $output->writeln('You must manually update the APP_KEY in your .env file:');
        $output->writeln('');
        $output->writeln('  1. Open .env in a text editor');
        $output->writeln('  2. Replace the existing APP_KEY value with the key above');
        $output->writeln('  3. Save the file');
        $output->writeln('');
        $output->writeln('After updating .env, re-enter the following settings in the admin panel:');
        $output->writeln('');

        $output->writeln('<comment>Payment gateways:</comment>');
        foreach (['stripe_secret_key', 'stripe_webhook_secret', 'paypal_client_secret', 'mollie_api_key', 'twocheckout_api_secret', 'twocheckout_secret_key', 'checkoutcom_secret_key', 'checkoutcom_webhook_secret'] as $key) {
            $output->writeln("  - {$key}");
        }

        $output->writeln('');
        $output->writeln('<comment>Mail services:</comment>');
        foreach (['mail_smtp_pass', 'mail_mailgun_api_key', 'mail_sendgrid_api_key', 'mail_resend_api_key', 'mail_unosend_api_key'] as $key) {
            $output->writeln("  - {$key}");
        }

        $output->writeln('');
        $output->writeln('<comment>SSO providers:</comment>');
        $output->writeln('  - All sso_*_client_secret values (Google, GitHub, etc.)');

        $output->writeln('');
        $output->writeln('<comment>2FA:</comment>');
        $output->writeln('  - All users with 2FA enabled will need to set up again');
        $output->writeln('  - Users who cannot log in can use the emergency password reset');

        $output->writeln('');
        $output->writeln('<comment>Encrypted addresses (permanent loss):</comment>');
        $output->writeln('  - Order billing/shipping address fields (name, phone, street, company)');
        $output->writeln('  - Customer profile billing/shipping address fields');
        $output->writeln('  - These become unreadable permanently. They are NOT re-enterable from the CLI.');

        $output->writeln('');
        $output->writeln('After re-entering settings, run:');
        $output->writeln('');
        $output->writeln('  php vale doctor');

        return Command::SUCCESS;
    }
}
