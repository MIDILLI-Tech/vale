<?php

declare(strict_types=1);

namespace ValeApp\App\Commands;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use ValeApp\Infrastructure\Media\MediaScanService;

final class MediaScanCommand extends Command
{
    public function __construct(
        private readonly MediaScanService $scanService,
        private readonly Connection $db,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('media:scan')
            ->setDescription('Scan upload directory for unregistered files and add them to the media library')
            ->addOption(
                'user',
                'u',
                InputOption::VALUE_REQUIRED,
                'Fallback user ID for scanned files (defaults to the first admin user)',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Only report what would be done, do not insert any records',
            )
            ->addOption(
                'no-organize',
                null,
                InputOption::VALUE_NONE,
                'Do not move files into YYYY/MM folders; register them at their current location (incl. upload root)',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Media Library Scan');

        $userId = $input->getOption('user');
        if ($userId !== null) {
            $userId = (int) $userId;
        } else {
            $userId = $this->resolveFallbackUserId($io);
            if ($userId === null) {
                $io->error('No admin user found. Create one first with: php vale user:create');
                return Command::FAILURE;
            }
        }

        $dryRun     = (bool) $input->getOption('dry-run');
        $noOrganize = (bool) $input->getOption('no-organize');
        $organize   = !$noOrganize;

        $io->text(sprintf('Fallback user ID: <info>%d</info>', $userId));
        $io->text(sprintf('Mode:            <info>%s</info>', $dryRun ? 'dry-run (no changes)' : 'live'));
        $io->text(sprintf('Organize:        <info>%s</info>', $organize ? 'yes (move to YYYY/MM)' : 'no (keep in place)'));

        if ($dryRun) {
            $io->warning('Dry-run mode — no records will be inserted.');
        }

        try {
            $result = $this->scanService->scan($userId, $output, $organize, $dryRun);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->section('Results');

        $io->writeln(sprintf('  Scanned:  <info>%d</info> files', $result['scanned']));
        $io->writeln(sprintf('  Already:  <comment>%d</comment> files (already in DB)', $result['skipped']));
        $io->writeln(sprintf('  Added:    <info>%d</info> files', $result['added']));
        $io->writeln(sprintf('  Updated:  <info>%d</info> records (variants backfilled)', $result['updated'] ?? 0));

        if ($result['errors'] !== []) {
            $io->section('Errors');
            foreach ($result['errors'] as $err) {
                $io->writeln(sprintf('  <error>%s</error> — %s', $err['file'], $err['error']));
            }
        }

        if ($dryRun) {
            $io->warning('Dry-run complete. No changes were made.');
        }

        $io->success('Scan complete.');

        return Command::SUCCESS;
    }

    private function resolveFallbackUserId(SymfonyStyle $io): ?int
    {
        $prefix = VL_DB_PREFIX;

        // Try to find a super_admin or admin user via roles pivot table
        $rows = $this->db->fetchAllAssociative(
            "SELECT u.id FROM {$prefix}users u
             INNER JOIN {$prefix}user_roles ur ON ur.user_id = u.id
             INNER JOIN {$prefix}roles r ON r.id = ur.role_id
             WHERE r.slug IN ('super_admin', 'admin')
             ORDER BY u.id ASC LIMIT 1",
        );

        if (!empty($rows)) {
            return (int) $rows[0]['id'];
        }

        // Fallback: first user in the system
        $rows = $this->db->fetchAllAssociative(
            "SELECT id FROM {$prefix}users ORDER BY id ASC LIMIT 1",
        );

        return !empty($rows) ? (int) $rows[0]['id'] : null;
    }
}
