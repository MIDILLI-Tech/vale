<?php

declare(strict_types=1);

namespace ValeApp\App\Commands;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use SimpleXMLElement;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use ValeApp\App\Commands\Concerns\BackfillsMediaVariants;
use ValeApp\App\Commands\Concerns\FlushesPageCache;
use ValeApp\Domain\Content\RoutingPrefixTypes;
use ValeApp\Domain\Content\RoutingValidator;
use ValeApp\Domain\Shared\Exception\DomainException;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Infrastructure\Auth\PasswordHasher;
use ValeApp\Infrastructure\Cache\PageCache;
use ValeApp\Infrastructure\Content\ButtonBlockConverter;
use ValeApp\Infrastructure\Content\ContentMarkerNormalizer;
use ValeApp\Infrastructure\Content\WpMediaUrlRewriter;
use ValeApp\Infrastructure\Http\RemoteFetchException;
use ValeApp\Infrastructure\Http\SafeRemoteFetcher;
use ValeApp\Infrastructure\Media\MediaFileTypes;
use ValeApp\Infrastructure\Media\MediaScanService;
use ValeApp\Infrastructure\Persistence\DbalContentTypeRepository;
use ValeApp\Infrastructure\Persistence\DbalPostRepository;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Security\Encrypter;

/**
 * Import content from WordPress — supports SQL dumps, live DB, and WXR (XML) exports.
 *
 * Usage:
 *   php vale import:wordpress --db-dump=dump.sql            # SQL dump
 *   php vale import:wordpress --wp-db=wp --wp-user=root     # Live DB
 *   php vale import:wordpress --file=export.xml              # WXR file
 *   php vale import:wordpress --fix-slugs                    # Fix slugs only
 *   php vale import:wordpress --cleanup-vale                 # Clean WP tables from Vale DB
 */
final class ImportWordPressCommand extends Command
{
    use BackfillsMediaVariants;
    use FlushesPageCache;

    private SymfonyStyle $io;
    private ?Connection $wpDb = null;
    private string $wpPrefix;
    private bool $dryRun                 = false;
    private bool $downloadMedia          = false;
    private bool $downloadProductFiles   = false;
    private bool $allowPrivateHosts      = false;
    private ?SafeRemoteFetcher $fetcher  = null;
    private bool $interactive            = false;
    private string $wizardSourceKind     = '';   // '' | 'wxr' | 'dump' | 'db'
    private string $wizardFilePath       = '';
    private array $wizardDb              = [];   // host, port, db, user, pass, prefix
    private bool $wizardPrefixSet        = false;
    private bool $importMediaSelected    = false; // wizard: attachment import requested
    private string $wizardMediaPath      = '';
    private array $userMap               = [];
    private array $termMap               = []; // old_term_taxonomy_id => new_vale_id
    private array $termIdMap             = []; // old_term_id => new_vale_id
    private array $postMap               = []; // old_post_id => new_vale_id
    private array $wooProductMap         = []; // old WooCommerce product post_id => new vale product_id
    private array $mediaMap              = []; // old_attachment_id => new_vale_id
    private array $importedMediaUrls     = []; // original URL => stored relative path
    private array $commentMap            = []; // old_comment_id => new_vale_id
    private array $orderMap              = []; // old_order_id => new_vale_id
    private string $mediaPath            = '';
    private array $preDumpTables         = [];
    private bool $importAll              = true;
    private bool $importPostsOnly        = false;
    private bool $importWooOnly          = false;
    private bool $importUsersOnly        = false;
    private bool $importSubscribersOnly  = false;
    private bool $importSeoOnly          = false;
    private string $defaultLocale        = 'en';
    private array $wxrTranslations       = []; // To collect Polylang translations in WXR mode
    /** @var array<int, int> WP term_id => Vale term id (WXR mode) */
    private array $wxrTermIds            = [];
    /** @var array<string, array<string, int>> table => WP slug => Vale term id (WXR mode) */
    private array $wxrTermSlugs          = [];
    /** @var list<array{table: string, child_id: int, parent_ref: string}> WXR term parent links, resolved after all terms exist */
    private array $wxrPendingTermParents = [];
    private array $wooDigitalReport      = []; // Digital-download handling notes (failed downloads, fallbacks)
    private int $sqlDumpSuppressed       = 0;  // Benign duplicate-key/object failures skipped during dump load
    private array $sqlDumpWarnings       = []; // Unexpected SQL statement failures collected for the report
    private array $wooProductFileOrder   = []; // product_id => next sort order for product_download_files
    private array $wooVariationFileOrder = []; // variation_id => next sort order for product_variation_download_files
    /** @var list<array{url: string, reason: string}> Media downloads refused by the safety guards */
    private array $skippedMediaDownloads = [];

    /** Uploads larger than this are refused instead of streamed to disk. */
    private const MAX_MEDIA_DOWNLOAD_BYTES = 104857600; // 100MB

    /**
     * Tables the importer cannot work without. A missing one is a hard failure.
     *
     * @var list<string>
     */
    private const REQUIRED_WP_TABLES = [
        'users',
        'posts',
        'postmeta',
        'terms',
        'term_taxonomy',
        'term_relationships',
    ];

    /**
     * Tables only some import steps read. A missing one warns and skips the
     * affected step instead of failing the whole import. Values describe what
     * the table is used for, so the warning can name the skipped step.
     *
     * @var array<string, string> table name => purpose
     */
    private const OPTIONAL_WP_TABLES = [
        'options'                    => 'WooCommerce settings/currency, Polylang, site options',
        'comments'                   => 'comment import',
        'mailpoet_subscribers'       => 'MailPoet newsletter subscriber import',
        'wc_orders'                  => 'HPOS order table',
        'wc_order_addresses'         => 'HPOS order addresses',
        'wc_orders_meta'             => 'HPOS order meta',
        'wc_order_items'             => 'HPOS order items',
        'woocommerce_order_items'    => 'order line items',
        'woocommerce_order_itemmeta' => 'order item meta',
    ];

    // WXR namespace URIs
    private const NS_WP      = 'http://wordpress.org/export/1.2/';
    private const NS_DC      = 'http://purl.org/dc/elements/1.1/';
    private const NS_CONTENT = 'http://purl.org/rss/1.0/modules/content/';
    private const NS_EXCERPT = 'http://wordpress.org/export/1.2/excerpt/';

    /**
     * WP page slugs that are WooCommerce shortcode/block shells (empty or
     * non-renderable in Vale) and usually shadow system routes. Always
     * skipped on import, reported at the end.
     *
     * @var list<string>
     */
    private const WOO_SYSTEM_PAGE_SLUGS = ['cart', 'checkout', 'my-account', 'shop'];

    /** @var list<array{title: string, slug: string, reason: string}> */
    private array $skippedPages                   = [];
    private int $copiedVariants                   = 0;
    private ?RoutingValidator $routingValidator   = null;
    private ?DbalSettingsRepository $valeSettings = null;

    public function __construct(
        private readonly Connection $valeDb,
        private readonly PasswordHasher $hasher,
        private readonly ?MediaScanService $scanner = null,
        private readonly ?PageCache $pageCache = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('import:wordpress')
            ->setDescription('Import content from WordPress — supports DB dump, live DB, and WXR (XML) exports')
            ->addOption('wp-host', null, InputOption::VALUE_REQUIRED, 'WordPress DB host', '127.0.0.1')
            ->addOption('wp-port', null, InputOption::VALUE_REQUIRED, 'WordPress DB port', '3306')
            ->addOption('wp-db', null, InputOption::VALUE_REQUIRED, 'WordPress DB name')
            ->addOption('wp-user', null, InputOption::VALUE_REQUIRED, 'WordPress DB user')
            ->addOption('wp-pass', null, InputOption::VALUE_REQUIRED, 'WordPress DB password')
            ->addOption('wp-prefix', null, InputOption::VALUE_REQUIRED, 'WordPress table prefix', 'wp_')
            ->addOption('db-dump', null, InputOption::VALUE_REQUIRED, 'Path to WordPress SQL dump file')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path to WordPress WXR (XML) export file')
            ->addOption('media-path', null, InputOption::VALUE_REQUIRED, 'Path to wp-content/uploads for local media copy')
            ->addOption('import-media', null, InputOption::VALUE_NONE, 'Import media files')
            ->addOption('download-media', null, InputOption::VALUE_NONE, 'Download attachment media from URLs')
            ->addOption(
                'allow-private-hosts',
                null,
                InputOption::VALUE_NONE,
                'Allow media downloads from private/internal hosts (local WordPress imports)',
            )
            ->addOption('download-product-files', null, InputOption::VALUE_NONE, 'Download WooCommerce digital files (media library)')
            ->addOption('cleanup', null, InputOption::VALUE_NONE, 'Drop WP tables from the active DB after import')
            ->addOption('cleanup-vale', null, InputOption::VALUE_NONE, 'Drop WP tables from Vale\'s DB')
            ->addOption('keep-wp-tables', null, InputOption::VALUE_NONE, 'Keep WordPress tables in Vale\'s DB after a dump import')
            ->addOption('fix-slugs', null, InputOption::VALUE_NONE, 'Fix non-compliant slugs in destination database')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview changes without writing to database')
            ->addOption('post-types', null, InputOption::VALUE_OPTIONAL, 'Additional post types to import', '')
            ->addOption('type', null, InputOption::VALUE_OPTIONAL, 'Force import type: db or wxr (auto-detected)', '')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Import all content (default)')
            ->addOption('posts', null, InputOption::VALUE_NONE, 'Import posts, pages, terms, comments, and SEO')
            ->addOption('woocommerce', null, InputOption::VALUE_NONE, 'Import WooCommerce products, categories, variations, orders')
            ->addOption('users', null, InputOption::VALUE_NONE, 'Import users/authors')
            ->addOption('subscribers', null, InputOption::VALUE_NONE, 'Import newsletter subscribers')
            ->addOption('seo', null, InputOption::VALUE_NONE, 'Import SEO metadata');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io                   = new SymfonyStyle($input, $output);
        $this->io->title('Vale WordPress Importer');
        $this->wpPrefix             = $input->getOption('wp-prefix');
        $this->dryRun               = (bool) $input->getOption('dry-run');
        $this->downloadMedia        = (bool) $input->getOption('download-media');
        $this->allowPrivateHosts    = (bool) $input->getOption('allow-private-hosts');
        $this->downloadProductFiles = (bool) $input->getOption('download-product-files');

        $all         = (bool) $input->getOption('all');
        $posts       = (bool) $input->getOption('posts');
        $woocommerce = (bool) $input->getOption('woocommerce');
        $users       = (bool) $input->getOption('users');
        $subscribers = (bool) $input->getOption('subscribers');
        $seo         = (bool) $input->getOption('seo');

        $hasSpecific = ($posts || $woocommerce || $users || $subscribers || $seo);

        // No module selected: run the interactive wizard when a terminal is
        // available; otherwise keep the legacy "import everything" behaviour.
        if (!$all && !$hasSpecific && !$input->getOption('fix-slugs') && !$input->getOption('cleanup-vale')) {
            if ($input->isInteractive()) {
                $this->interactive = true;
                if (!$this->runInteractiveWizard($input)) {
                    return Command::FAILURE;
                }
            } else {
                $this->importAll = true;
            }
        } else {
            $this->importAll             = $all;
            $this->importPostsOnly       = $posts;
            $this->importWooOnly         = $woocommerce;
            $this->importUsersOnly       = $users;
            $this->importSubscribersOnly = $subscribers;
            $this->importSeoOnly         = $seo;
        }

        $this->defaultLocale = $this->getDefaultFallbackLocale();

        // Standalone: --fix-slugs only
        if (
            $input->getOption('fix-slugs')
            && !$input->getOption('db-dump')
            && !$input->getOption('wp-db')
            && !$input->getOption('file')
        ) {
            $this->fixDestinationSlugs();
            $this->flushPageCache($this->pageCache, $this->dryRun, $this->io);
            return Command::SUCCESS;
        }

        // Standalone: --cleanup-vale only
        if (
            $input->getOption('cleanup-vale')
            && !$input->getOption('db-dump')
            && !$input->getOption('wp-db')
            && !$input->getOption('file')
        ) {
            $this->cleanupValeTables();
            $this->flushPageCache($this->pageCache, $this->dryRun, $this->io);
            return Command::SUCCESS;
        }

        // Detect mode: --type flag, --file, DB options, or a wizard selection
        $type    = $input->getOption('type');
        $wxrFile = $input->getOption('file');

        $wantWxr = $type === 'wxr' || $wxrFile
                                   || ($this->interactive && $this->wizardSourceKind === 'wxr');
        $wantDb  = $type === 'db'  || $input->getOption('db-dump') || $input->getOption('wp-db')
                                   || ($this->interactive && in_array($this->wizardSourceKind, ['dump', 'db'], true));

        if ($wantWxr) {
            $wxrFile = $wxrFile ?: $this->wizardFilePath;
            if (!$wxrFile) {
                $this->io->error('WXR file path is required for WXR mode. Use --file=xml or --type=wxr --file=xml');
                return Command::FAILURE;
            }
            return $this->executeWxrMode($wxrFile, $input);
        }

        if ($wantDb) {
            return $this->executeDbMode($input);
        }

        $this->io->error('No import source specified. Use --db-dump, --wp-db, --file, or run interactively.');
        return Command::FAILURE;
    }

    /**
     * Interactive wizard: asks for source, modules, media handling and dry-run
     * so the command can run without any flags.
     */
    private function runInteractiveWizard(InputInterface $input): bool
    {
        $this->io->section('Interactive Import — Answer a few questions');

        // 1) Source
        $file = (string) $input->getOption('file');
        $dump = (string) $input->getOption('db-dump');
        $dbWp = (string) $input->getOption('wp-db');

        if ($file !== '') {
            $this->wizardSourceKind = 'wxr';
            $this->wizardFilePath   = $file;
        } elseif ($dump !== '') {
            $this->wizardSourceKind = 'dump';
            $this->wizardFilePath   = $dump;
        } elseif ($dbWp !== '') {
            $this->wizardSourceKind = 'db';
        } else {
            $source = $this->io->choice('What is the import source?', [
                'wxr'  => 'WXR (XML) export file',
                'dump' => 'SQL database dump',
                'db'   => 'Live WordPress database (MySQL)',
            ], 'wxr');

            if ($source === 'wxr') {
                $this->wizardSourceKind = 'wxr';
                $this->wizardFilePath   = (string) $this->io->ask('Path to the WXR (XML) export file');
            } elseif ($source === 'dump') {
                $this->wizardSourceKind = 'dump';
                $this->wizardFilePath   = (string) $this->io->ask('Path to the SQL dump file');
            } else {
                $this->wizardSourceKind = 'db';
                $this->wizardDb         = [
                    'host'   => (string) $this->io->ask('WordPress DB host', '127.0.0.1'),
                    'port'   => (string) $this->io->ask('WordPress DB port', '3306'),
                    'db'     => (string) $this->io->ask('WordPress DB name'),
                    'user'   => (string) $this->io->ask('WordPress DB user'),
                    'pass'   => (string) $this->io->askHidden('WordPress DB password'),
                    'prefix' => (string) $this->io->ask('WordPress table prefix', 'wp_'),
                ];
                $this->wizardPrefixSet = true;
                $this->wpPrefix        = $this->wizardDb['prefix'];
            }
        }

        if (in_array($this->wizardSourceKind, ['wxr', 'dump'], true) && $this->wizardFilePath !== '') {
            if (!file_exists($this->wizardFilePath)) {
                $this->io->error("Import file not found: {$this->wizardFilePath}");
                return false;
            }
        }

        if ($this->wizardSourceKind === 'dump') {
            $this->io->note('WordPress tables introduced by the dump are removed automatically after the import (use --keep-wp-tables to keep them).');
        }

        // 2) Modules
        $modules  = ['All', 'Users', 'Content', 'WooCommerce', 'Subscribers', 'SEO'];
        $question = new ChoiceQuestion('Which modules should be imported?', $modules, 0);
        $question->setMultiselect(true);
        $question->setErrorMessage('Your selection is invalid.');
        $selected = (array) $this->io->askQuestion($question);

        if (in_array('All', $selected, true)) {
            $this->importAll = true;
        } else {
            $this->importAll             = false;
            $this->importUsersOnly       = in_array('Users', $selected, true);
            $this->importPostsOnly       = in_array('Content', $selected, true);
            $this->importWooOnly         = in_array('WooCommerce', $selected, true);
            $this->importSubscribersOnly = in_array('Subscribers', $selected, true);
            $this->importSeoOnly         = in_array('SEO', $selected, true);
        }

        // 3) Media
        $mediaOptions = [
            'none'     => 'Skip media (import content only)',
            'download' => 'Download attachments from their original URLs',
        ];
        if ($this->wizardSourceKind !== 'wxr') {
            $mediaOptions['local'] = 'Copy from a local wp-content/uploads directory';
        }
        $mediaChoice = $this->io->choice('How should image/media files be handled?', $mediaOptions, 'none');

        if ($mediaChoice === 'download') {
            $this->downloadMedia       = true;
            $this->importMediaSelected = true;
        } elseif ($mediaChoice === 'local') {
            $this->importMediaSelected = true;
            $this->wizardMediaPath     = (string) $this->io->ask(
                'Path to wp-content/uploads',
                base_path('storage/uploads')
            );
        }

        // 4) Digital product files (only relevant for WooCommerce imports)
        if ($this->importAll || $this->importWooOnly) {
            $this->io->text('Digital product files can be downloaded into the media library, or kept external.');
            $this->downloadProductFiles = $this->io->confirm(
                'Download WooCommerce digital product/variation files (if any)?',
                false
            );
        }

        // 5) Dry run
        if (!$input->getOption('dry-run')) {
            $this->dryRun = $this->io->confirm(
                'Run as a dry run (no changes written to the database)?',
                false
            );
        }

        $this->io->newLine();

        return true;
    }

    // ══════════════════════════════════════════════════════════════════════
    //  DB mode
    // ══════════════════════════════════════════════════════════════════════

    private function executeDbMode(InputInterface $input): int
    {
        $dbDump = $input->getOption('db-dump');
        $dbName = $input->getOption('wp-db');
        $wpUser = $input->getOption('wp-user');
        $wpPass = $input->getOption('wp-pass');
        $wpHost = $input->getOption('wp-host');
        $wpPort = (int) $input->getOption('wp-port');

        // Interactive wizard overrides the source options.
        if ($this->interactive && $this->wizardSourceKind === 'dump') {
            $dbDump = $this->wizardFilePath;
        } elseif ($this->interactive && $this->wizardSourceKind === 'db') {
            if (isset($this->wizardDb['host'])) {
                $wpHost = $this->wizardDb['host'];
            }
            if (isset($this->wizardDb['port'])) {
                $wpPort = (int) $this->wizardDb['port'];
            }
            $dbName                = $this->wizardDb['db']     ?? $dbName;
            $wpUser                = $this->wizardDb['user']   ?? '';
            $wpPass                = $this->wizardDb['pass']   ?? '';
            $this->wpPrefix        = $this->wizardDb['prefix'] ?? $this->wpPrefix;
            $this->wizardPrefixSet = true;
        }

        if (!$this->wizardPrefixSet) {
            $this->wpPrefix = $input->getOption('wp-prefix');
        }

        if ($this->dryRun) {
            $this->io->note('Dry run — changes will be rolled back at the end.');
        }

        if ($dbDump) {
            $this->preDumpTables = $this->getAllTableNames();
        }

        try {
            if ($dbDump) {
                if (!file_exists($dbDump)) {
                    $this->io->error("SQL dump file not found: {$dbDump}");
                    return Command::FAILURE;
                }
                $this->io->note("Loading SQL dump: {$dbDump}");
                if (!$this->loadSqlDump($dbDump)) {
                    return Command::FAILURE;
                }
                $this->wpDb = $this->valeDb;
            } else {
                if (!$dbName) {
                    $this->io->error('WordPress database name (--wp-db) or --db-dump is required.');
                    return Command::FAILURE;
                }

                if (!$wpUser || !$wpPass) {
                    $this->io->error('--wp-user and --wp-pass are required for a live WordPress database.');
                    $this->io->text('Example: <info>--wp-db=wordpress --wp-user=root --wp-pass=secret</info>');
                    return Command::FAILURE;
                }

                try {
                    $this->wpDb = DriverManager::getConnection([
                        'driver'   => 'pdo_mysql',
                        'host'     => $wpHost,
                        'port'     => $wpPort,
                        'dbname'   => $dbName,
                        'user'     => $wpUser,
                        'password' => $wpPass,
                        'charset'  => 'utf8mb4',
                    ]);
                    $this->wpDb->connect();
                } catch (\Exception $e) {
                    $this->io->error('Could not connect to WordPress database: ' . $e->getMessage());
                    return Command::FAILURE;
                }
            }
            $this->io->success('Connected to WordPress database.');

            if (!$this->validateWpTables()) {
                return Command::FAILURE;
            }

            $this->warnMissingOptionalTables();

            $importMedia = (bool) $input->getOption('import-media')
                || ($this->interactive && $this->importMediaSelected);

            if ($this->dryRun && $importMedia) {
                $this->io->note(
                    'Media import is skipped during dry run '
                    . '(no files are copied or downloaded).'
                );
            }

            if ($this->dryRun) {
                $this->valeDb->beginTransaction();
            }

            if ($this->importAll || $this->importUsersOnly || $this->importWooOnly) {
                $this->importUsers();
            }

            if (!$this->dryRun && $importMedia) {
                $this->mediaPath = $this->interactive
                    ? $this->wizardMediaPath
                    : (string) $input->getOption('media-path');
                if ($this->mediaPath && !is_dir($this->mediaPath)) {
                    $this->io->error('Media path (--media-path) must be a valid directory.');
                    return Command::FAILURE;
                }
                if (!$this->mediaPath && !$this->downloadMedia) {
                    $this->io->error('Use --media-path or --download-media for media import.');
                    return Command::FAILURE;
                }
                $this->importMedia();
            }

            if ($this->importAll || $this->importPostsOnly || $this->importWooOnly) {
                $this->importTerms();
            }

            if ($this->importAll || $this->importPostsOnly) {
                $extraTypes = $input->getOption('post-types') ? explode(',', $input->getOption('post-types')) : [];
                $this->importPosts(array_map('trim', $extraTypes));
                $this->importPages();
            }

            if ($this->importAll || $this->importPostsOnly || $this->importSubscribersOnly) {
                $this->importNewsletterSubscribers();
            }

            if ($this->importAll || $this->importWooOnly) {
                $this->importWooProducts();
                $this->importOrders();
            }

            if ($this->importAll || $this->importPostsOnly || $this->importWooOnly) {
                $this->importComments();
            }

            if ($this->importAll || $this->importPostsOnly || $this->importWooOnly) {
                $this->importMenusDb();
            }

            if ($this->importSeoOnly) {
                $this->importSeoOnly();
            }

            $this->io->section('Finalizing');
            $this->io->text('Resolving Polylang translation relationships...');
            $this->resolvePolylangTranslations();
            $this->io->text('Resolving parent-child relationships...');
            $this->resolveParents();
            $this->io->text('Updating media links in content...');
            $this->updateContentMediaLinks();
            $this->io->text('Importing WooCommerce currency...');
            $this->importWooCommerceCurrency();
            $this->io->text('Importing WooCommerce shop settings...');
            $this->importWooCommerceShopSettings();

            // Dump mode: WordPress tables introduced by the dump are removed
            // automatically (opt out with --keep-wp-tables). Live DB mode keeps
            // the explicit --cleanup confirmation flow.
            if ($dbDump && !$this->dryRun && !$input->getOption('keep-wp-tables')) {
                $this->cleanupImportedWordPressTables();
            } elseif ($input->getOption('cleanup') && !$this->dryRun && !$dbDump) {
                $msg = 'DROP WordPress tables (' . $this->wpPrefix . '*) from the remote database?';
                if ($this->io->confirm($msg, false)) {
                    $this->cleanupWordPressTables();
                }
            }

            if ($input->getOption('fix-slugs')) {
                $this->fixDestinationSlugs();
            }

            if ($input->getOption('cleanup-vale')) {
                $this->cleanupValeTables();
            }

            if ($this->dryRun) {
                $this->valeDb->rollBack();
                $this->io->note('Dry run — all changes rolled back.');
            }

            $this->backfillMediaVariants();
            $this->printWooDigitalReport();
            $this->printSqlDumpWarnings();
            $this->printImportSummary($input, false);

            $this->flushPageCache($this->pageCache, $this->dryRun, $this->io);

            $this->io->success('WordPress import completed successfully!' . ($this->dryRun ? ' (dry run)' : ''));

            return Command::SUCCESS;
        } catch (\Exception $e) {
            if ($this->dryRun && $this->valeDb->isTransactionActive()) {
                $this->valeDb->rollBack();
            }
            $this->io->error('Import failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function importUsers(): void
    {
        $this->io->section('Importing Users');
        $wpUsers = $this->wpDb->fetchAllAssociative("SELECT * FROM {$this->wpPrefix}users");

        $progressBar = new ProgressBar($this->io, count($wpUsers));
        foreach ($wpUsers as $wpUser) {
            $existing = $this->valeDb->fetchAssociative(
                'SELECT id FROM ' . VL_DB_PREFIX . 'users WHERE email = ?',
                [$wpUser['user_email']]
            );

            if ($existing) {
                $this->userMap[$wpUser['ID']] = (int) $existing['id'];
            } else {
                $this->valeDb->insert(VL_DB_PREFIX . 'users', [
                    'email'      => $wpUser['user_email'],
                    'password'   => $this->hasher->hash(bin2hex(random_bytes(16))),
                    'name'       => $wpUser['display_name'] ?: $wpUser['user_login'],
                    'created_at' => $wpUser['user_registered'],
                    'is_active'  => 0,
                ]);
                $newId                        = (int) $this->valeDb->lastInsertId();
                $this->userMap[$wpUser['ID']] = $newId;

                $subscriberRole = $this->valeDb->fetchOne(
                    'SELECT id FROM ' . VL_DB_PREFIX . 'roles WHERE slug = ?',
                    ['subscriber']
                );
                if ($subscriberRole) {
                    $this->valeDb->insert(VL_DB_PREFIX . 'user_roles', [
                        'user_id' => $newId,
                        'role_id' => $subscriberRole,
                    ]);
                }
            }
            $progressBar->advance();
        }
        $progressBar->finish();
        $this->io->newLine(2);
    }

    private function importTerms(): void
    {
        $this->io->section('Importing Categories and Tags');

        $taxonomies = [
            'category'     => 'post',
            'post_tag'     => 'post',
            'product_cat'  => 'product',
            'product_tag'  => 'product'
        ];

        if ($this->importPostsOnly) {
            unset($taxonomies['product_cat'], $taxonomies['product_tag']);
        } elseif ($this->importWooOnly) {
            unset($taxonomies['category'], $taxonomies['post_tag']);
        }

        foreach ($taxonomies as $wpTax => $valeType) {
            $this->io->text("Processing {$wpTax}...");
            try {
                $terms = $this->wpDb->fetchAllAssociative("
                    SELECT t.*, tt.term_taxonomy_id, tt.taxonomy, tt.parent, tt.description
                    FROM {$this->wpPrefix}terms t
                    INNER JOIN {$this->wpPrefix}term_taxonomy tt ON t.term_id = tt.term_id
                    WHERE tt.taxonomy = ?
                ", [$wpTax]);
            } catch (\Exception $e) {
                $this->io->warning("Could not fetch terms for {$wpTax}: " . $e->getMessage());
                continue;
            }

            if (empty($terms)) {
                continue;
            }

            $progressBar = new ProgressBar($this->io, count($terms));
            foreach ($terms as $term) {
                $table = ($valeType === 'product' && $wpTax === 'product_cat')
                    ? VL_DB_PREFIX . 'product_categories'
                    : (($wpTax === 'post_tag' || $wpTax === 'product_tag')
                        ? VL_DB_PREFIX . 'tags'
                        : VL_DB_PREFIX . 'categories');

                $lang = $this->wpGetObjectLanguage($term['term_id']);
                if ($lang === 'en' && $term['term_id'] !== $term['term_taxonomy_id']) {
                    $lang = $this->wpGetObjectLanguage($term['term_taxonomy_id']);
                }

                $termName = $this->cleanImportedText((string) $term['name']);
                $data     = [
                    'name'         => $termName,
                    'slug'         => (string) Slug::fromString($term['slug'] ?: $termName),
                    'language'     => $lang,
                    'created_at'   => date('Y-m-d H:i:s'),
                ];

                if ($table !== VL_DB_PREFIX . 'tags') {
                    $data['description'] = $term['description'];
                }

                if ($table !== VL_DB_PREFIX . 'product_categories') {
                    $data['content_type'] = $valeType;
                }

                $existingId = $this->valeDb->fetchOne(
                    'SELECT id FROM ' . $table . ' WHERE slug = ? AND language = ?',
                    [$data['slug'], $lang]
                );

                if ($existingId) {
                    $this->termMap[$term['term_taxonomy_id']] = (int) $existingId;
                    $this->termIdMap[$term['term_id']]        = (int) $existingId;
                    $progressBar->advance();
                    continue;
                }

                try {
                    $this->valeDb->insert($table, $data);
                } catch (\Exception $e) {
                    $this->io->warning("\nFailed to import term: {$term['name']}\nError: " . $e->getMessage());
                    $progressBar->advance();
                    continue;
                }
                $newTermId                                = (int) $this->valeDb->lastInsertId();
                $this->termMap[$term['term_taxonomy_id']] = $newTermId;
                $this->termIdMap[$term['term_id']]        = $newTermId;
                $progressBar->advance();
            }
            $progressBar->finish();
            $this->io->newLine();
        }
    }

    private function importMedia(): void
    {
        $this->io->section('Importing Media');
        $uploadsDir = dirname(__DIR__, 2) . '/storage/uploads';
        $wpMedia    = $this->wpDb->fetchAllAssociative("
            SELECT * FROM {$this->wpPrefix}posts
            WHERE post_type = 'attachment'
        ");

        if (empty($wpMedia)) {
            $this->io->note('No media found.');
            return;
        }

        $progressBar = new ProgressBar($this->io, count($wpMedia));
        foreach ($wpMedia as $media) {
            $metas = $this->wpDb->fetchAllAssociative(
                "SELECT meta_key, meta_value FROM {$this->wpPrefix}postmeta WHERE post_id = ?",
                [$media['ID']]
            );
            $metaMap = [];
            foreach ($metas as $m) {
                $metaMap[$m['meta_key']] = $m['meta_value'];
            }

            $guid           = $media['guid']                ?? '';
            $wpFileRelative = $metaMap['_wp_attached_file'] ?? '';
            $originalName   = basename((string) (parse_url((string) $guid, PHP_URL_PATH) ?: ''));
            if ($originalName === '') {
                $originalName = $wpFileRelative !== '' ? basename($wpFileRelative) : '';
            }

            // Try local file copy first (--media-path), then download (--download-media)
            $sourceFile = null;
            $downloaded = false;

            if ($wpFileRelative && $this->mediaPath) {
                $candidate = rtrim($this->mediaPath, '/') . '/' . $wpFileRelative;
                if (file_exists($candidate)) {
                    $sourceFile = $candidate;
                }
            }

            if (!$sourceFile && $this->downloadMedia && $guid) {
                $downloadedFile = $this->importDownloadAttachment($guid);
                if ($downloadedFile) {
                    $sourceFile = $downloadedFile;
                    $downloaded = true;
                }
            }

            if (!$sourceFile) {
                $progressBar->advance();
                continue;
            }

            // Determine the final relative path under storage/uploads.
            if ($downloaded) {
                // The downloader already preserves the URL's subdirectory layout.
                $relativePath = ltrim(substr($sourceFile, strlen($uploadsDir)), '/\\');
            } else {
                // Local copy: keep the WP folder structure and the original file
                // name. Path-based dedupe below replaces the old timestamp-rename
                // hack, so stored paths always match what content references.
                $relativePath = $wpFileRelative !== '' ? $wpFileRelative : basename($sourceFile);
                $targetFile   = $uploadsDir . '/' . $relativePath;
                if (!is_file($targetFile)) {
                    if (!is_dir(dirname($targetFile)) && !mkdir(dirname($targetFile), 0755, true)) {
                        $progressBar->advance();
                        continue;
                    }
                    if (!copy($sourceFile, $targetFile)) {
                        $progressBar->advance();
                        continue;
                    }
                }
            }

            $mediaPath = $uploadsDir . '/' . $relativePath;
            if (!is_file($mediaPath)) {
                $progressBar->advance();
                continue;
            }

            // WordPress resized siblings (-300x200, -scaled, …) share the
            // content URLs rewritten later; copy them next to the original
            // so those URLs resolve. Files only — no media rows.
            if (!$downloaded) {
                $this->copyImageSizeVariants($sourceFile, $uploadsDir, $relativePath);
            }

            // Path-based dedupe: an attachment is identified by where it is
            // stored, not by its basename (the same name can exist in different
            // WP years/months and must not be conflated).
            $existing = $this->valeDb->fetchAssociative(
                'SELECT id FROM ' . VL_DB_PREFIX . 'media WHERE path = ?',
                [$relativePath]
            );
            if ($existing) {
                $this->mediaMap[$media['ID']] = (int) $existing['id'];
                if ($guid) {
                    $this->importedMediaUrls[] = ['url' => $guid, 'path' => $relativePath];
                }
                $progressBar->advance();
                continue;
            }

            // Validate + sanitize the file against the site's media policy.
            $mimeType = $media['post_mime_type'] ?: null;
            if ($this->scanner !== null) {
                try {
                    $mimeType = $this->scanner->sanitizeFile($mediaPath);
                } catch (\Throwable $e) {
                    $this->io->warning("\nSkipped media {$originalName}: " . $e->getMessage());
                    $progressBar->advance();
                    continue;
                }
            }

            $this->valeDb->insert(VL_DB_PREFIX . 'media', [
                'user_id'       => $this->userMap[$media['post_author']] ?? 1,
                'filename'      => basename($relativePath),
                'original_name' => $originalName,
                'mime_type'     => $mimeType,
                'size'          => filesize($mediaPath),
                'path'          => $relativePath,
                'alt_text'      => $this->cleanImportedText((string) ($metaMap['_wp_attachment_image_alt'] ?? '')),
                'title'         => $this->cleanImportedText((string) $media['post_title']),
                'description'   => $this->resolveMediaDescription(
                    $media['post_content'] ?? null,
                    $metaMap,
                    $media['post_excerpt'] ?? null
                ),
                'created_at'    => $media['post_date'],
                'disk'          => 'local'
            ]);

            $this->mediaMap[$media['ID']] = (int) $this->valeDb->lastInsertId();
            if ($guid) {
                $this->importedMediaUrls[] = ['url' => $guid, 'path' => $relativePath];
            }

            $progressBar->advance();
        }
        $progressBar->finish();
        $this->io->newLine(2);
        if ($this->copiedVariants > 0) {
            $this->io->text(sprintf('Copied %d WordPress size variant file(s) alongside originals.', $this->copiedVariants));
        }
    }

    /**
     * Copy WordPress resized siblings (photo-300x200.jpg, photo-scaled.png)
     * sitting next to an imported original into the mirrored uploads
     * subfolder, so rewritten content URLs resolve. Files only.
     */
    private function copyImageSizeVariants(string $sourceFile, string $uploadsDir, string $relativePath): void
    {
        $ext = strtolower((string) pathinfo($sourceFile, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            return;
        }
        $dir     = dirname($sourceFile);
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }
        $pattern   = '/^' . preg_quote((string) pathinfo($sourceFile, PATHINFO_FILENAME), '/') . '-(\d+x\d+|scaled)\.' . preg_quote($ext, '/') . '$/i';
        $targetDir = dirname($uploadsDir . '/' . $relativePath);
        foreach ($entries as $entry) {
            if (!preg_match($pattern, (string) $entry)) {
                continue;
            }
            $dest = $targetDir . '/' . $entry;
            if (is_file($dest)) {
                continue;
            }
            if (@copy($dir . '/' . $entry, $dest)) {
                $this->copiedVariants++;
            }
        }
    }

    /**
     * Download a single attachment from a URL to the local uploads directory.
     *
     * The URL comes from imported WordPress data, so it is treated as
     * untrusted: the fetch is redirect-hardened and cannot reach a private
     * address (unless --allow-private-hosts was passed for a local import),
     * the stored path can never escape storage/uploads, and executable
     * extensions from the media blocklist are refused.
     */
    private function importDownloadAttachment(string $url): ?string
    {
        $uploadsDir = dirname(__DIR__, 2) . '/storage/uploads';

        $relativePath = ltrim((string) (parse_url($url, PHP_URL_PATH) ?: '/'), '/');
        if (preg_match('#^(?:wp-content/)?uploads/(.+)$#', $relativePath, $m)) {
            $relativePath = $m[1];
        }

        $relativePath = $this->sanitizeUploadRelativePath($relativePath, $url);
        if ($relativePath === null) {
            return null;
        }

        $destPath = $uploadsDir . '/' . $relativePath;
        if (is_file($destPath)) {
            return $destPath;
        }

        return $this->downloadIntoUploads($url, $destPath, 'Vale Importer/1.0')
            ? $destPath
            : null;
    }

    /**
     * Normalizes a URL path into a safe path below storage/uploads: separators
     * are unified, dot segments dropped, ".." refused (so the write can never
     * escape the uploads directory) and executable extensions from the media
     * blocklist rejected. Returns null when the path cannot be stored safely.
     */
    private function sanitizeUploadRelativePath(string $relativePath, string $url): ?string
    {
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $relativePath)) as $segment) {
            $segment = trim($segment);
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                $this->noteSkippedDownload($url, 'path escapes the uploads directory');
                return null;
            }
            $segments[] = $segment;
        }

        if ($segments === []) {
            return null;
        }

        $filename = $segments[count($segments) - 1];
        if (MediaFileTypes::isBlockedExtension((string) pathinfo($filename, PATHINFO_EXTENSION))) {
            $this->noteSkippedDownload($url, 'blocked file extension');
            return null;
        }

        return implode('/', $segments);
    }

    /**
     * Streams a remote file to $destPath without buffering it in memory, then
     * moves it into place. Returns false (and writes nothing) on any failure,
     * including a body larger than $maxBytes.
     */
    private function downloadIntoUploads(string $url, string $destPath, string $userAgent): bool
    {
        $destDir = dirname($destPath);
        if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
            return false;
        }

        $partialPath = $destPath . '.part';

        try {
            $fetch = $this->remoteFetcher()->open($url, $userAgent, 30);
        } catch (RemoteFetchException $exception) {
            $this->noteRefusedDownload($url, $exception->getReason());
            return false;
        }

        if ($fetch['status'] !== 200) {
            fclose($fetch['stream']);
            return false;
        }

        $out = @fopen($partialPath, 'wb');
        if ($out === false) {
            fclose($fetch['stream']);
            return false;
        }

        $bytes  = 0;
        $stream = $fetch['stream'];
        while (!feof($stream)) {
            $chunk = fread($stream, 8192);
            if ($chunk === false) {
                break;
            }
            if ($chunk === '') {
                continue;
            }

            $bytes += strlen($chunk);
            if ($bytes > self::MAX_MEDIA_DOWNLOAD_BYTES) {
                $this->noteSkippedDownload($url, 'larger than the download size limit');
                fclose($stream);
                fclose($out);
                @unlink($partialPath);
                return false;
            }

            fwrite($out, $chunk);
        }

        fclose($stream);
        fclose($out);

        if ($bytes === 0 || !@rename($partialPath, $destPath)) {
            @unlink($partialPath);
            return false;
        }

        return true;
    }

    private function remoteFetcher(): SafeRemoteFetcher
    {
        return $this->fetcher ??= new SafeRemoteFetcher(allowPrivateHosts: $this->allowPrivateHosts);
    }

    /** Records a download refused by a safety guard, reported at the end of the run. */
    private function noteRefusedDownload(string $url, string $reason): void
    {
        if (in_array($reason, [
            RemoteFetchException::REASON_PRIVATE_HOST,
            RemoteFetchException::REASON_INVALID_SCHEME,
            RemoteFetchException::REASON_INVALID_REDIRECT,
        ], true)) {
            $this->noteSkippedDownload($url, $reason);
        }
    }

    private function noteSkippedDownload(string $url, string $reason): void
    {
        $this->skippedMediaDownloads[] = ['url' => $url, 'reason' => $reason];
    }
    private function importPosts(array $extraTypes = []): void
    {
        $this->io->section('Importing Posts');

        $postTypes    = array_merge(['post'], $extraTypes);
        $placeholders = implode(',', array_fill(0, count($postTypes), '?'));

        $wpPosts = $this->wpDb->fetchAllAssociative("
            SELECT * FROM {$this->wpPrefix}posts
            WHERE post_type IN ({$placeholders}) AND post_status IN ('publish', 'draft', 'future', 'pending', 'private')
        ", $postTypes);

        if (empty($wpPosts)) {
            $this->io->note("No posts found for types: " . implode(', ', $postTypes));
            return;
        }

        $this->io->text(sprintf('Found %d posts.', count($wpPosts)));

        $progressBar = new ProgressBar($this->io, count($wpPosts));
        foreach ($wpPosts as $wpPost) {
            $status = $wpPost['post_status'] === 'publish' ? 'published' : ($wpPost['post_status'] === 'future' ? 'scheduled' : 'draft');
            $slug   = (string) Slug::fromString($wpPost['post_name'] ?: $this->cleanImportedText((string) $wpPost['post_title']) ?: 'post-' . $wpPost['ID']);
            $lang   = $this->wpGetObjectLanguage($wpPost['ID']);

            // Idempotency check: check if post already exists
            $existingId = $this->valeDb->fetchOne(
                'SELECT id FROM ' . VL_DB_PREFIX . 'posts WHERE slug = ? AND content_type = ? AND language = ?',
                [$slug, $wpPost['post_type'], $lang]
            );

            if ($existingId) {
                $newPostId                    = (int) $existingId;
                $this->postMap[$wpPost['ID']] = $newPostId;
                $this->importPostTerms($wpPost['ID'], $newPostId);
                $this->importSeoMeta($wpPost['ID'], $newPostId, 'post');
                $progressBar->advance();
                continue;
            }

            if ($wpPost['post_type'] !== 'post') {
                $this->ensureContentTypeExists($wpPost['post_type'], ucfirst($wpPost['post_type']));
            }

            try {
                $authorId = $this->userMap[$wpPost['post_author']] ?? null;
                if (!$authorId) {
                    $authorId = $this->valeDb->fetchOne('SELECT id FROM ' . VL_DB_PREFIX . 'users LIMIT 1') ?: 1;
                }

                $this->valeDb->insert(VL_DB_PREFIX . 'posts', [
                    'content_type'     => $wpPost['post_type'],
                    'author_id'        => $authorId,
                    'title'            => $this->cleanImportedText((string) $wpPost['post_title']) ?: '(no title)',
                    'slug'             => $slug,
                    'excerpt'          => $wpPost['post_excerpt'],
                    'body'             => ButtonBlockConverter::convertWordPress(ContentMarkerNormalizer::normalize((string) $wpPost['post_content'])),
                    'status'           => $status,
                    'published_at'     => $wpPost['post_date'],
                    'created_at'       => $wpPost['post_date'],
                    'updated_at'       => $wpPost['post_modified'],
                    'comment_status'   => $wpPost['comment_status'] === 'open' ? 'enabled' : 'disabled',
                    'language'         => $lang,
                ]);
            } catch (\Exception $e) {
                $this->io->warning("\nFailed to import post: {$wpPost['post_title']}\nError: " . $e->getMessage());
                $progressBar->advance();
                continue;
            }

            $newPostId                    = (int) $this->valeDb->lastInsertId();
            $this->postMap[$wpPost['ID']] = $newPostId;

            $this->importPostTerms($wpPost['ID'], $newPostId);
            $this->importSeoMeta($wpPost['ID'], $newPostId, 'post');

            $thumbnailId = $this->wpDb->fetchOne("SELECT meta_value FROM {$this->wpPrefix}postmeta WHERE post_id = ? AND meta_key = '_thumbnail_id'", [$wpPost['ID']]);
            if ($thumbnailId && isset($this->mediaMap[$thumbnailId])) {
                $this->valeDb->update(
                    VL_DB_PREFIX . 'posts',
                    ['featured_image_id' => $this->mediaMap[$thumbnailId]],
                    ['id'                => $newPostId]
                );
            }

            $progressBar->advance();
        }
        $progressBar->finish();
        $this->io->newLine(2);
    }

    /**
     * Lazily build a RoutingValidator over the destination DB. Never throws:
     * when it cannot be built (e.g. missing APP_KEY) the route-conflict
     * check is skipped with a warning instead of failing the import.
     */
    private function routingValidator(): ?RoutingValidator
    {
        if ($this->routingValidator !== null || $this->routingInitTried) {
            return $this->routingValidator;
        }
        $this->routingInitTried = true;
        try {
            $settings               = new DbalSettingsRepository($this->valeDb, new Encrypter((string) env('APP_KEY', '')));
            $this->valeSettings     = $settings;
            $this->routingValidator = new RoutingValidator(
                $settings,
                new DbalContentTypeRepository($this->valeDb),
                new DbalPostRepository($this->valeDb),
                [$this->defaultLocale],
            );
        } catch (\Throwable $e) {
            $this->io->warning('Route-conflict check disabled: ' . $e->getMessage());
        }
        return $this->routingValidator;
    }

    /**
     * Returns a skip reason when a WP page must not be imported as a Vale
     * page: WooCommerce system shells, or a slug that would be shadowed by a
     * system route / routing prefix. Null means importable.
     */
    private function pageImportSkipReason(string $slug, string $title): ?string
    {
        if (in_array($slug, self::WOO_SYSTEM_PAGE_SLUGS, true)) {
            return 'WooCommerce system page (shortcode shell, dead content in Vale)';
        }
        $validator = $this->routingValidator();
        if ($validator === null || $this->valeSettings === null) {
            return null;
        }
        $prefix   = trim((string) ($this->valeSettings->get('routing_page_prefix', '') ?? ''));
        $fullPath = ($prefix !== '' ? '/' . ltrim($prefix, '/') : '') . '/' . $slug;
        $error    = $validator->getPagePathConflictError($fullPath);
        return $error === null ? null : 'Route conflict: ' . $error;
    }

    /**
     * @param list<array{title: string, slug: string, reason: string}> $skipped
     */
    private function printSkippedPagesReport(): void
    {
        if ($this->skippedPages === []) {
            return;
        }
        $this->io->warning(sprintf('Skipped %d page(s):', count($this->skippedPages)));
        $this->io->table(
            ['Title', 'Slug', 'Reason'],
            array_map(
                static fn (array $s): array => [$s['title'], $s['slug'], $s['reason']],
                $this->skippedPages
            )
        );
    }

    /**
     * Decode HTML entities in plain-text fields coming from WordPress
     * (WXR CDATA keeps "&amp;" literally; dumps may double-encode).
     * Never apply to HTML bodies. Runs until stable, max 3 passes.
     */
    private function cleanImportedText(string $text): string
    {
        $text = trim($text);
        for ($i = 0; $i < 3; $i++) {
            $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $text) {
                break;
            }
            $text = $decoded;
        }
        return (string) str_replace("\u{00A0}", ' ', $text);
    }

    private function importPages(): void
    {
        $this->io->section('Importing Pages');
        $wpPages = $this->wpDb->fetchAllAssociative("
            SELECT * FROM {$this->wpPrefix}posts
            WHERE post_type = 'page' AND post_status IN ('publish', 'draft', 'private')
        ");

        if (empty($wpPages)) {
            $this->io->note('No pages found.');
            return;
        }

        $this->io->text(sprintf('Found %d pages.', count($wpPages)));

        $progressBar = new ProgressBar($this->io, count($wpPages));
        foreach ($wpPages as $wpPage) {
            $slug = (string) Slug::fromString($wpPage['post_name'] ?: $this->cleanImportedText((string) $wpPage['post_title']) ?: 'page-' . $wpPage['ID']);
            $lang = $this->wpGetObjectLanguage($wpPage['ID']);

            // Idempotency check: check if page already exists
            $existingId = $this->valeDb->fetchOne(
                'SELECT id FROM ' . VL_DB_PREFIX . 'posts WHERE slug = ? AND content_type = ? AND language = ?',
                [$slug, 'page', $lang]
            );

            if ($existingId) {
                $newPageId                    = (int) $existingId;
                $this->postMap[$wpPage['ID']] = $newPageId;
                $this->importSeoMeta($wpPage['ID'], $newPageId, 'post');
                $progressBar->advance();
                continue;
            }

            $skipReason = $this->pageImportSkipReason($slug, $this->cleanImportedText((string) ($wpPage['post_title'] ?: '(no title)')));
            if ($skipReason !== null) {
                $this->skippedPages[] = [
                    'title'  => $this->cleanImportedText((string) ($wpPage['post_title'] ?: '(no title)')),
                    'slug'   => $slug,
                    'reason' => $skipReason,
                ];
                $progressBar->advance();
                continue;
            }

            try {
                $authorId = $this->userMap[$wpPage['post_author']] ?? null;
                if (!$authorId) {
                    // Try to find any admin if the author is missing
                    $authorId = $this->valeDb->fetchOne('SELECT id FROM ' . VL_DB_PREFIX . 'users LIMIT 1') ?: 1;
                }

                $this->valeDb->insert(VL_DB_PREFIX . 'posts', [
                    'content_type'     => 'page',
                    'author_id'        => $authorId,
                    'title'            => $this->cleanImportedText((string) $wpPage['post_title']) ?: '(no title)',
                    'slug'             => $slug,
                    'body'             => ButtonBlockConverter::convertWordPress(ContentMarkerNormalizer::normalize((string) $wpPage['post_content'])),
                    'status'           => $wpPage['post_status'] === 'publish' ? 'published' : 'draft',
                    'published_at'     => $wpPage['post_date'],
                    'created_at'       => $wpPage['post_date'],
                    'updated_at'       => $wpPage['post_modified'],
                    'language'         => $lang,
                    'comment_status'   => $wpPage['comment_status'] === 'open' ? 'enabled' : 'disabled',
                ]);
            } catch (\Exception $e) {
                $this->io->warning("\nFailed to import page: {$wpPage['post_title']}\nError: " . $e->getMessage());
                $progressBar->advance();
                continue;
            }

            $newPageId                    = (int) $this->valeDb->lastInsertId();
            $this->postMap[$wpPage['ID']] = $newPageId;
            $this->importSeoMeta($wpPage['ID'], $newPageId, 'post');

            $thumbnailId = $this->wpDb->fetchOne("SELECT meta_value FROM {$this->wpPrefix}postmeta WHERE post_id = ? AND meta_key = '_thumbnail_id'", [$wpPage['ID']]);
            if ($thumbnailId && isset($this->mediaMap[$thumbnailId])) {
                $this->valeDb->update(
                    VL_DB_PREFIX . 'posts',
                    ['featured_image_id' => $this->mediaMap[$thumbnailId]],
                    ['id'                => $newPageId]
                );
            }

            $progressBar->advance();
        }
        $progressBar->finish();
        $this->io->newLine(2);
        $this->printSkippedPagesReport();
    }

    private function importWooProducts(): void
    {
        $this->io->section('Importing WooCommerce Products');
        $wpProducts = $this->wpDb->fetchAllAssociative("
            SELECT * FROM {$this->wpPrefix}posts
            WHERE post_type = 'product' AND post_status IN ('publish', 'draft', 'private')
        ");

        if (empty($wpProducts)) {
            $this->io->note('No WooCommerce products found.');
            return;
        }

        $this->io->text(sprintf('Found %d products.', count($wpProducts)));

        $progressBar = new ProgressBar($this->io, count($wpProducts));
        foreach ($wpProducts as $wpProduct) {
            $slug = (string) Slug::fromString($wpProduct['post_name'] ?: $this->cleanImportedText((string) $wpProduct['post_title']) ?: 'product-' . $wpProduct['ID']);
            $lang = $this->wpGetObjectLanguage($wpProduct['ID']);

            // Idempotency check: check if product already exists
            $existingId = $this->valeDb->fetchOne(
                'SELECT id FROM ' . VL_DB_PREFIX . 'products WHERE slug = ? AND language = ?',
                [$slug, $lang]
            );

            if ($existingId) {
                $newProductId                          = (int) $existingId;
                $this->postMap[$wpProduct['ID']]       = $newProductId; // Map product ID
                $this->wooProductMap[$wpProduct['ID']] = $newProductId;
                $this->importProductTerms($wpProduct['ID'], $newProductId);
                $this->importSeoMeta($wpProduct['ID'], $newProductId, 'product');
                $progressBar->advance();
                continue;
            }

            $metas   = $this->wpDb->fetchAllAssociative("SELECT meta_key, meta_value FROM {$this->wpPrefix}postmeta WHERE post_id = ?", [$wpProduct['ID']]);
            $metaMap = [];
            foreach ($metas as $m) {
                $metaMap[$m['meta_key']] = $m['meta_value'];
            }

            // Digital products: virtual and/or downloadable WooCommerce products.
            $isVirtual      = ($metaMap['_virtual'] ?? 'no')      === 'yes';
            $isDownloadable = ($metaMap['_downloadable'] ?? 'no') === 'yes';
            $isDigital      = $isVirtual || $isDownloadable;

            try {
                $this->valeDb->insert(VL_DB_PREFIX . 'products', [
                    'name'                  => $this->cleanImportedText((string) $wpProduct['post_title']) ?: '(no name)',
                    'slug'                  => $slug,
                    'description'           => $wpProduct['post_content'],
                    'short_description'     => $wpProduct['post_excerpt'],
                    'price'                 => (float) ($metaMap['_regular_price'] ?? 0),
                    'sale_price'            => (isset($metaMap['_sale_price']) && $metaMap['_sale_price'] !== '') ? (float) $metaMap['_sale_price'] : null,
                    'sku'                   => $metaMap['_sku'] ?? null,
                    'stock_quantity'        => (int) ($metaMap['_stock'] ?? 0),
                    'stock_status'          => ($metaMap['_stock_status'] ?? 'instock') === 'instock' ? 'in_stock' : 'out_of_stock',
                    'status'                => $wpProduct['post_status']                === 'publish' ? 'published' : 'draft',
                    'comment_status'        => $wpProduct['comment_status']             === 'open' ? 'enabled' : 'disabled',
                    'shipping_class'        => $isDigital ? 'digital' : 'use_global',
                    'download_source'       => 'none',
                    'download_url'          => null,
                    'download_limit'        => 0,
                    'download_expiry_days'  => 0,
                    'published_at'          => $wpProduct['post_date'],
                    'created_at'            => $wpProduct['post_date'],
                    'updated_at'            => $wpProduct['post_modified'],
                    'language'              => $lang,
                    'type'                  => 'simple'
                ]);
            } catch (\Exception $e) {
                $this->io->warning("\nFailed to import product: {$wpProduct['post_title']}\nError: " . $e->getMessage());
                $progressBar->advance();
                continue;
            }

            $newProductId                          = (int) $this->valeDb->lastInsertId();
            $this->postMap[$wpProduct['ID']]       = $newProductId; // Map product ID
            $this->wooProductMap[$wpProduct['ID']] = $newProductId;

            // Import digital download configuration (files / external URL / limits).
            if ($isDownloadable) {
                $this->applyWooProductDownloads($newProductId, $wpProduct['post_title'], $metaMap);
            }

            $this->importProductTerms($wpProduct['ID'], $newProductId);
            $this->importSeoMeta($wpProduct['ID'], $newProductId, 'product');
            $this->importWooVariations($wpProduct['ID'], $newProductId);

            $thumbnailId = $metaMap['_thumbnail_id'] ?? null;
            if ($thumbnailId && isset($this->mediaMap[$thumbnailId])) {
                $this->valeDb->update(
                    VL_DB_PREFIX . 'products',
                    ['featured_image_id' => $this->mediaMap[$thumbnailId]],
                    ['id'                => $newProductId]
                );
            }

            $progressBar->advance();
        }
        $progressBar->finish();
        $this->io->newLine(2);

        $this->linkWooRelatedProducts();
    }

    /**
     * Link WooCommerce upsell/cross-sell relationships (DB import mode).
     *
     * WooCommerce stores `_upsell_ids` / `_crosssell_ids` postmeta as
     * serialized arrays of product post IDs. This runs as a second pass so the
     * referenced products are already mapped to their Vale IDs.
     */
    private function linkWooRelatedProducts(): void
    {
        if ($this->wooProductMap === []) {
            return;
        }

        $ids          = array_keys($this->wooProductMap);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $rows = $this->wpDb->fetchAllAssociative("
            SELECT post_id, meta_key, meta_value
            FROM {$this->wpPrefix}postmeta
            WHERE post_id IN ({$placeholders})
              AND meta_key IN ('_upsell_ids', '_crosssell_ids')
        ", $ids);

        $updates = [];
        foreach ($rows as $row) {
            $valeProductId = $this->wooProductMap[$row['post_id']] ?? null;
            if (!$valeProductId) {
                continue;
            }
            $resolved = $this->resolveWooRelatedIds($this->parseWooRelatedIdList((string) $row['meta_value']));
            if ($resolved === []) {
                continue;
            }
            $column                           = $row['meta_key'] === '_upsell_ids' ? 'upsell_ids' : 'cross_sell_ids';
            $updates[$valeProductId][$column] = json_encode($resolved);
        }

        if ($updates === []) {
            return;
        }

        foreach ($updates as $productId => $columns) {
            $this->valeDb->update(VL_DB_PREFIX . 'products', $columns, ['id' => (int) $productId]);
        }

        $this->io->text(sprintf('Linked upsell/cross-sell relationships on %d product(s).', count($updates)));
    }

    /**
     * Parse a WooCommerce related-product meta value (serialized array or
     * comma-separated list) into a flat list of int product post IDs.
     *
     * @return array<int, int>
     */
    private function parseWooRelatedIdList(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $decoded = @unserialize($raw, ['allowed_classes' => false]);
        $ids     = is_array($decoded) ? $decoded : explode(',', $raw);

        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    /**
     * Resolve a list of WooCommerce product post IDs to Vale product IDs via
     * the product map (unmapped IDs are dropped).
     *
     * @param array<int, int> $ids
     * @return array<int, int>
     */
    private function resolveWooRelatedIds(array $ids): array
    {
        $resolved = [];
        foreach ($ids as $id) {
            $valeId = $this->wooProductMap[$id] ?? null;
            if ($valeId && $valeId > 0) {
                $resolved[] = (int) $valeId;
            }
        }

        return array_values(array_unique($resolved));
    }

    /**
     * Parse a WooCommerce `_downloadable_files` serialized value into a flat
     * list of ['name' => string, 'url' => string] entries. Returns [] when the
     * value is empty/unserializable.
     *
     * @return array<int, array{name: string, url: string}>
     */
    private function parseWooDownloadableFiles(array $metaMap): array
    {
        $raw = $metaMap['_downloadable_files'] ?? '';
        if (is_string($raw) && $raw !== '') {
            $decoded = @unserialize($raw, ['allowed_classes' => false]);
            if (is_array($decoded)) {
                $files = [];
                foreach ($decoded as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $name = is_string($entry['name'] ?? null) ? trim($entry['name']) : '';
                    $url  = is_string($entry['file'] ?? null) ? trim($entry['file']) : '';
                    if ($url === '') {
                        continue;
                    }
                    if ($name === '') {
                        $name = basename((string) parse_url($url, PHP_URL_PATH));
                    }
                    $files[] = ['name' => $name, 'url' => $url];
                }
                return $files;
            }
        }
        return [];
    }

    /**
     * Resolve a WooCommerce download limit/expiry (-1 = unlimited -> 0 for Vale).
     */
    private function wooDownloadNumber(string $val, int $fallback = 0): int
    {
        if ($val === '' || $val === null) {
            return $fallback;
        }
        $n = (int) $val;
        return $n < 0 ? 0 : $n;
    }

    /**
     * Import a download file into Vale's media library. Returns the media id, or
     * null when the file could not be downloaded/validated.
     */
    private function importWooDownloadMedia(string $url, string $name): ?int
    {
        $uploadsDir   = dirname(__DIR__, 2) . '/storage/uploads';
        $sourceFile   = $this->importDownloadAttachment($url);
        $relativePath = null;

        if ($sourceFile !== null && str_starts_with($sourceFile, $uploadsDir . DIRECTORY_SEPARATOR)) {
            $relativePath = ltrim(substr($sourceFile, strlen($uploadsDir)), '/\\');
        }

        if ($relativePath === null || $relativePath === '') {
            return null;
        }

        $mediaPath = $uploadsDir . '/' . $relativePath;
        if (!is_file($mediaPath)) {
            return null;
        }

        $mimeType = 'application/octet-stream';
        if ($this->scanner !== null) {
            try {
                $mimeType = $this->scanner->sanitizeFile($mediaPath);
            } catch (\Throwable $e) {
                $this->io->warning("Skipped WooCommerce download file {$name}: " . $e->getMessage());
                return null;
            }
        } else {
            $mimeType = mime_content_type($mediaPath) ?: 'application/octet-stream';
        }

        $existing = $this->valeDb->fetchOne(
            'SELECT id FROM ' . VL_DB_PREFIX . 'media WHERE path = ?',
            [$relativePath]
        );
        if ($existing) {
            return (int) $existing;
        }

        $this->valeDb->insert(VL_DB_PREFIX . 'media', [
            'user_id'       => 1,
            'filename'      => basename($relativePath),
            'original_name' => $name !== '' ? $name : basename($relativePath),
            'mime_type'     => $mimeType,
            'size'          => filesize($mediaPath),
            'path'          => $relativePath,
            'alt_text'      => '',
            'title'         => $name,
            'created_at'    => date('Y-m-d H:i:s'),
            'disk'          => 'local',
        ]);

        return (int) $this->valeDb->lastInsertId();
    }

    /**
     * Apply WooCommerce digital-download configuration to a product or variation.
     *
     * @param array<string,string> $metaMap
     * @param string $ownerLabel  Product/variation display name (for the report)
     * @param callable(int,int):void $storeFile  callback($masterId, $mediaId) persisting a downloaded file
     * @param callable(string $source, int $limit, int $expiry, ?string $url):void $storeConfig
     */
    private function applyWooDownloads(
        array $metaMap,
        string $ownerLabel,
        int $masterId,
        callable $storeFile,
        callable $storeConfig
    ): void {
        $files   = $this->parseWooDownloadableFiles($metaMap);
        $limit   = $this->wooDownloadNumber((string) ($metaMap['_download_limit'] ?? ''));
        $expiry  = $this->wooDownloadNumber((string) ($metaMap['_download_expiry'] ?? ''));

        $firstUrl = $files[0]['url'] ?? null;

        // Download files into the media library when requested.
        if ($firstUrl !== null && $this->downloadProductFiles && !$this->dryRun) {
            $stored     = 0;
            $failedUrls = [];
            foreach ($files as $file) {
                $mediaId = $this->importWooDownloadMedia($file['url'], $file['name']);
                if ($mediaId === null) {
                    $failedUrls[] = $file['url'];
                    continue;
                }
                $storeFile($masterId, $mediaId);
                $stored++;
            }

            if ($stored > 0) {
                // Product-level source/limit only meaningful for simple products;
                // config callback decides what to write.
                $storeConfig('internal', $limit, $expiry, null);
                $this->wooDigitalReport[] = "[{$ownerLabel}] {$stored} download file(s) imported into media library.";
            } else {
                // Nothing could be downloaded - fall back to the external URL.
                $storeConfig('external', $limit, $expiry, $firstUrl);
                $this->wooDigitalReport[] = "[{$ownerLabel}] Could not download any file; kept external URL: {$firstUrl}.";
            }

            foreach ($failedUrls as $u) {
                $this->wooDigitalReport[] = "[{$ownerLabel}] Skipped failed download: {$u}";
            }
            return;
        }

        // No download requested (or dry run): keep the external URL(s).
        if ($firstUrl !== null) {
            $storeConfig('external', $limit, $expiry, $firstUrl);
            $extra = count($files) - 1;
            $note  = $extra > 0
                ? "[{$ownerLabel}] Stored 1 external URL; {$extra} additional file(s) not imported (external multiple-file unsupported)."
                : "[{$ownerLabel}] Stored external URL: {$firstUrl}";
            $this->wooDigitalReport[] = $note;
            return;
        }

        // No downloadable files at all.
        $this->wooDigitalReport[] = "[{$ownerLabel}] No downloadable files found.";
    }

    private function printWooDigitalReport(): void
    {
        if ($this->wooDigitalReport === []) {
            return;
        }
        $this->io->section('WooCommerce Digital Downloads Report');
        foreach ($this->wooDigitalReport as $note) {
            $this->io->warning($note);
        }
        $this->io->text('Digital product download files were imported as external URL(s) unless --download-product-files was used to fetch them into the media library.');
        $this->io->newLine();
    }

    private function printSqlDumpWarnings(): void
    {
        if ($this->sqlDumpWarnings === []) {
            return;
        }
        $this->io->section('SQL Dump Warnings');
        $this->io->text(count($this->sqlDumpWarnings) . ' unexpected SQL statement failed during the dump load.');
        $shown = 0;
        foreach ($this->sqlDumpWarnings as $warning) {
            if ($shown >= 10) {
                break;
            }
            $this->io->warning($warning);
            $shown++;
        }
        if (count($this->sqlDumpWarnings) > $shown) {
            $this->io->text('...and ' . (count($this->sqlDumpWarnings) - $shown) . ' more.');
        }
        $this->io->newLine();
    }

    /**
     * Prints a short summary of which modules ran (or were skipped / not
     * selected) so the user can see at a glance what the import covered.
     */
    private function printImportSummary(InputInterface $input, bool $wxrMode): void
    {
        $this->io->section('Import Summary');

        $rows = [];
        if ($this->importAll) {
            $rows[] = ['Full import', 'all modules'];
        } else {
            $rows[] = ['Users', ($this->importUsersOnly || $this->importWooOnly)
                ? 'imported'
                : 'skipped (not selected)'];
            $rows[] = ['Content (posts, pages, terms, comments)', $this->importPostsOnly
                ? 'imported'
                : 'skipped (not selected)'];
            $rows[] = ['WooCommerce (products, variations, orders)', $this->importWooOnly
                ? 'imported'
                : 'skipped (not selected)'];
            $rows[] = ['Subscribers', $this->importSubscribersOnly
                ? ($wxrMode ? 'skipped (not available in WXR mode)' : 'imported')
                : 'skipped (not selected)'];
            $rows[] = ['SEO', $this->importSeoOnly
                ? 'imported'
                : ($this->importPostsOnly ? 'with content' : 'skipped (not selected)')];
        }

        if ($this->downloadMedia) {
            $rows[] = ['Media files', 'downloaded from URLs'];
        } elseif (!$wxrMode && ($this->importMediaSelected || $this->wizardMediaPath !== ''
                                                           || (bool) $input->getOption('import-media'))) {
            $rows[] = ['Media files', 'local copy / registered'];
        } else {
            $rows[] = ['Media files', 'skipped'];
        }

        $rows[] = ['Digital product files', $this->downloadProductFiles
            ? 'downloaded into media library'
            : 'external URLs kept'];

        $this->io->table(['Module', 'Status'], $rows);

        if ($this->skippedMediaDownloads !== []) {
            $this->printSkippedDownloadsReport();
        }
    }

    /**
     * Media downloads refused by the URL guards (private hosts, unsafe paths,
     * blocked extensions) must be visible — a silent skip looks like an empty
     * import.
     */
    private function printSkippedDownloadsReport(): void
    {
        $this->io->section('Skipped Media Downloads');
        $this->io->text(sprintf(
            '%d download(s) were refused by the safety guards:',
            count($this->skippedMediaDownloads),
        ));

        foreach (array_slice($this->skippedMediaDownloads, 0, 10) as $skipped) {
            $this->io->text('  - ' . $skipped['reason'] . ': ' . $skipped['url']);
        }
        if (count($this->skippedMediaDownloads) > 10) {
            $this->io->text(sprintf('  … and %d more.', count($this->skippedMediaDownloads) - 10));
        }

        foreach ($this->skippedMediaDownloads as $skipped) {
            if ($skipped['reason'] === RemoteFetchException::REASON_PRIVATE_HOST && !$this->allowPrivateHosts) {
                $this->io->text('Pass --allow-private-hosts to download media from a local WordPress install.');
                break;
            }
        }
    }

    private function applyWooProductDownloads(int $productId, string $title, array $metaMap): void
    {
        $this->applyWooDownloads(
            $metaMap,
            $title,
            $productId,
            function (int $pid, int $mediaId) {
                $this->valeDb->insert(VL_DB_PREFIX . 'product_download_files', [
                    'product_id' => $pid,
                    'media_id'   => $mediaId,
                    'sort_order' => $this->wooProductFileOrder[$pid] ?? 0,
                ]);
                $this->wooProductFileOrder[$pid] = ($this->wooProductFileOrder[$pid] ?? 0) + 1;
            },
            function (string $source, int $limit, int $expiry, ?string $url) use ($productId) {
                $this->valeDb->update(VL_DB_PREFIX . 'products', [
                    'download_source'      => $source,
                    'download_limit'       => $limit,
                    'download_expiry_days' => $expiry,
                    'download_url'         => $source === 'external' ? $url : null,
                ], ['id' => $productId]);
            }
        );
    }

    private function importWooVariations(int $wpProductId, int $valeProductId): void
    {
        $wpVariations = $this->wpDb->fetchAllAssociative("
            SELECT * FROM {$this->wpPrefix}posts
            WHERE post_type = 'product_variation' AND post_parent = ? AND post_status = 'publish'
        ", [$wpProductId]);

        if (empty($wpVariations)) {
            return;
        }

        $this->valeDb->update(VL_DB_PREFIX . 'products', ['type' => 'variable'], ['id' => $valeProductId]);

        $minPrice              = null;
        $variationCount        = 0;
        $digitalVariationCount = 0;
        foreach ($wpVariations as $wpVar) {
            $metas      = $this->wpDb->fetchAllAssociative("SELECT meta_key, meta_value FROM {$this->wpPrefix}postmeta WHERE post_id = ?", [$wpVar['ID']]);
            $metaMap    = [];
            $attributes = [];
            foreach ($metas as $m) {
                $metaMap[$m['meta_key']] = $m['meta_value'];
                if (str_starts_with($m['meta_key'], 'attribute_')) {
                    $attrName              = str_replace('attribute_', '', $m['meta_key']);
                    $attributes[$attrName] = $m['meta_value'];
                }
            }

            $isDownloadable = ($metaMap['_downloadable'] ?? 'no') === 'yes';
            $isVirtual      = ($metaMap['_virtual'] ?? 'no')      === 'yes';

            $variationPrice = (float) ($metaMap['_regular_price'] ?? 0);
            if ($variationPrice > 0.0 && ($minPrice === null || $variationPrice < $minPrice)) {
                $minPrice = $variationPrice;
            }

            $thumbId = $metaMap['_thumbnail_id'] ?? null;

            $this->valeDb->insert(VL_DB_PREFIX . 'product_variations', [
                'product_id'       => $valeProductId,
                'attributes'       => json_encode($attributes),
                'sku'              => $metaMap['_sku'] ?? null,
                'price'            => $variationPrice,
                'sale_price'       => (isset($metaMap['_sale_price']) && $metaMap['_sale_price'] !== '') ? (float) $metaMap['_sale_price'] : null,
                'stock_quantity'   => (int) ($metaMap['_stock'] ?? 0),
                'stock_status'     => ($metaMap['_stock_status'] ?? 'instock') === 'instock' ? 'in_stock' : 'out_of_stock',
                'weight'           => isset($metaMap['_weight']) ? (float) $metaMap['_weight'] : null,
                'image_id'         => $thumbId !== null && isset($this->mediaMap[$thumbId]) ? $this->mediaMap[$thumbId] : null,
                'shipping_class'   => ($isVirtual || $isDownloadable) ? 'digital' : 'use_parent',
                'download_mode'    => 'use_parent',
                'download_url'     => null,
            ]);

            $newVariationId = (int) $this->valeDb->lastInsertId();

            $variationCount++;
            if ($isVirtual || $isDownloadable) {
                $digitalVariationCount++;
            }

            if ($isDownloadable) {
                $this->applyWooVariationDownloads($newVariationId, $wpVar['ID'], $metaMap);
            }
        }

        if ($minPrice !== null) {
            $currentPrice = (float) $this->valeDb->fetchOne('SELECT price FROM ' . VL_DB_PREFIX . 'products WHERE id = ?', [$valeProductId]) ?: 0.0;
            if ($currentPrice <= 0.0) {
                $this->valeDb->update(VL_DB_PREFIX . 'products', ['price' => $minPrice], ['id' => $valeProductId]);
            }
        }

        // WooCommerce keeps Virtual/Downloadable on the variation rows for
        // variable products, so the parent imports as physical. When every
        // variation is digital the parent must be digital too.
        if ($variationCount > 0 && $digitalVariationCount === $variationCount) {
            $this->valeDb->update(VL_DB_PREFIX . 'products', ['shipping_class' => 'digital'], ['id' => $valeProductId]);
        }
    }

    private function applyWooVariationDownloads(int $variationId, int $wpVariationId, array $metaMap): void
    {
        $this->applyWooDownloads(
            $metaMap,
            "Variation #{$wpVariationId}",
            $variationId,
            function (int $vid, int $mediaId) {
                $this->valeDb->insert(VL_DB_PREFIX . 'product_variation_download_files', [
                    'variation_id' => $vid,
                    'media_id'     => $mediaId,
                    'sort_order'   => $this->wooVariationFileOrder[$vid] ?? 0,
                ]);
                $this->wooVariationFileOrder[$vid] = ($this->wooVariationFileOrder[$vid] ?? 0) + 1;
            },
            function (string $source, int $limit, int $expiry, ?string $url) use ($variationId) {
                $this->valeDb->update(VL_DB_PREFIX . 'product_variations', [
                    'download_mode'        => $source === 'none' ? 'use_parent' : $source,
                    'download_limit'       => $limit,
                    'download_expiry_days' => $expiry,
                    'download_url'         => $source === 'external' ? $url : null,
                ], ['id' => $variationId]);
            }
        );
    }

    private function importNewsletterSubscribers(): void
    {
        $this->io->section('Importing Newsletter Subscribers (MailPoet)');

        $exists = $this->tableExists($this->wpDb, $this->wpPrefix . 'mailpoet_subscribers');
        if (!$exists) {
            return;
        }

        $wpSubscribers = $this->wpDb->fetchAllAssociative("SELECT * FROM {$this->wpPrefix}mailpoet_subscribers");
        if (empty($wpSubscribers)) {
            return;
        }

        $progressBar = new ProgressBar($this->io, count($wpSubscribers));
        foreach ($wpSubscribers as $wpSub) {
            $existing = $this->valeDb->fetchOne('SELECT id FROM ' . VL_DB_PREFIX . 'newsletter_subscribers WHERE email = ?', [$wpSub['email']]);
            if ($existing) {
                $progressBar->advance();
                continue;
            }

            $this->valeDb->insert(VL_DB_PREFIX . 'newsletter_subscribers', [
                'email'         => $wpSub['email'],
                'name'          => ($wpSub['first_name'] || $wpSub['last_name']) ? trim($wpSub['first_name'] . ' ' . $wpSub['last_name']) : null,
                'status'        => $wpSub['status'] === 'subscribed' ? 'active' : 'pending',
                'token'         => bin2hex(random_bytes(32)),
                'created_at'    => $wpSub['created_at'] ?? date('Y-m-d H:i:s'),
                'confirmed_at'  => $wpSub['status'] === 'subscribed' ? ($wpSub['updated_at'] ?? date('Y-m-d H:i:s')) : null,
            ]);
            $progressBar->advance();
        }
        $progressBar->finish();
        $this->io->newLine(2);
    }

    private function importPostTerms(int $wpPostId, int $valePostId): void
    {
        try {
            $wpTerms = $this->wpDb->fetchAllAssociative("
                SELECT tt.term_taxonomy_id, tt.taxonomy
                FROM {$this->wpPrefix}term_relationships tr
                INNER JOIN {$this->wpPrefix}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                WHERE tr.object_id = ? AND tt.taxonomy IN ('category', 'post_tag')
            ", [$wpPostId]);
        } catch (\Exception) {
            $wpTerms = [];
        }

        if (empty($wpTerms)) {
            try {
                $wpTerms = $this->wpDb->fetchAllAssociative("
                    SELECT term_taxonomy_id FROM {$this->wpPrefix}term_relationships WHERE object_id = ?
                ", [$wpPostId]);
            } catch (\Exception) {
                $wpTerms = [];
            }
        }

        foreach ($wpTerms as $wpTerm) {
            $valeTermId = $this->termMap[$wpTerm['term_taxonomy_id']] ?? null;
            if (!$valeTermId) {
                continue;
            }

            if ($wpTerm['taxonomy'] === 'category') {
                $exists = $this->valeDb->fetchOne(
                    'SELECT id FROM ' . VL_DB_PREFIX . 'post_categories WHERE post_id = ? AND category_id = ?',
                    [$valePostId, $valeTermId]
                );
                if ($exists) {
                    continue;
                }
                $this->valeDb->insert(VL_DB_PREFIX . 'post_categories', [
                    'post_id'     => $valePostId,
                    'category_id' => $valeTermId,
                ]);
            } else {
                $exists = $this->valeDb->fetchOne(
                    'SELECT id FROM ' . VL_DB_PREFIX . 'post_tags WHERE post_id = ? AND tag_id = ?',
                    [$valePostId, $valeTermId]
                );
                if ($exists) {
                    continue;
                }
                $this->valeDb->insert(VL_DB_PREFIX . 'post_tags', [
                    'post_id' => $valePostId,
                    'tag_id'  => $valeTermId,
                ]);
            }
        }
    }

    private function importProductTerms(int $wpPostId, int $valeProductId): void
    {
        $wpTerms = $this->wpDb->fetchAllAssociative("
            SELECT tt.term_taxonomy_id, tt.taxonomy
            FROM {$this->wpPrefix}term_relationships tr
            INNER JOIN {$this->wpPrefix}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
            WHERE tr.object_id = ? AND tt.taxonomy IN ('product_cat', 'product_tag')
        ", [$wpPostId]);

        if (empty($wpTerms)) {
            $wpTerms = $this->wpDb->fetchAllAssociative("
                SELECT term_taxonomy_id FROM {$this->wpPrefix}term_relationships WHERE object_id = ?
            ", [$wpPostId]);
        }

        foreach ($wpTerms as $wpTerm) {
            $valeTermId = $this->termMap[$wpTerm['term_taxonomy_id']] ?? null;
            if (!$valeTermId) {
                continue;
            }

            if ($wpTerm['taxonomy'] === 'product_cat') {
                $exists = $this->valeDb->fetchOne(
                    'SELECT id FROM ' . VL_DB_PREFIX . 'product_to_categories WHERE product_id = ? AND category_id = ?',
                    [$valeProductId, $valeTermId]
                );
                if ($exists) {
                    continue;
                }
                $this->valeDb->insert(VL_DB_PREFIX . 'product_to_categories', [
                    'product_id'  => $valeProductId,
                    'category_id' => $valeTermId,
                ]);
            }
        }
    }

    private function resolveParents(): void
    {
        // Only resolve when posts/content or WooCommerce are being imported
        if (!$this->importAll && !$this->importPostsOnly && !$this->importWooOnly) {
            return;
        }

        $this->io->text('Resolving parent-child relationships...');

        $wpCategories = $this->wpDb->fetchAllAssociative("
            SELECT tt.term_taxonomy_id, tt.parent
            FROM {$this->wpPrefix}term_taxonomy tt
            WHERE tt.parent > 0
        ");

        foreach ($wpCategories as $wpCat) {
            if (isset($this->termMap[$wpCat['term_taxonomy_id']]) && isset($this->termMap[$wpCat['parent']])) {
                $this->valeDb->update(
                    VL_DB_PREFIX . 'categories',
                    ['parent_id' => $this->termMap[$wpCat['parent']]],
                    ['id'        => $this->termMap[$wpCat['term_taxonomy_id']]]
                );
                $this->valeDb->update(
                    VL_DB_PREFIX . 'product_categories',
                    ['parent_id' => $this->termMap[$wpCat['parent']]],
                    ['id'        => $this->termMap[$wpCat['term_taxonomy_id']]]
                );
            }
        }

        $wpPages = $this->wpDb->fetchAllAssociative("
            SELECT ID, post_parent FROM {$this->wpPrefix}posts
            WHERE post_type = 'page' AND post_parent > 0
        ");

        foreach ($wpPages as $wpPage) {
            if (isset($this->postMap[$wpPage['ID']]) && isset($this->postMap[$wpPage['post_parent']])) {
                $this->valeDb->update(
                    VL_DB_PREFIX . 'posts',
                    ['parent_id' => $this->postMap[$wpPage['post_parent']]],
                    ['id'        => $this->postMap[$wpPage['ID']]]
                );
            }
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    //  Menus (DB + WXR)
    // ══════════════════════════════════════════════════════════════════════

    /** @var list<array{name: string, slug: string, items: int}> */
    private array $importedMenus = [];
    /** @var list<array{menu: string, title: string, reason: string}> */
    private array $skippedMenuItems = [];
    /** @var list<array{menu: string, label: string, url: string}> */
    private array $menuLinkCheck   = [];
    private bool $routingInitTried = false;

    /**
     * DB mode: import nav menus. Runs after pages/products/categories so
     * reference maps are ready. Menus are imported without a location
     * (locations vary by theme); the report suggests an assignment.
     */
    private function importMenusDb(): void
    {
        $this->io->section('Importing Menus');

        try {
            $terms = $this->wpDb->fetchAllAssociative("
                SELECT t.term_id, t.name, t.slug
                FROM {$this->wpPrefix}terms t
                INNER JOIN {$this->wpPrefix}term_taxonomy tt ON t.term_id = tt.term_id
                WHERE tt.taxonomy = 'nav_menu'
            ");
        } catch (\Exception $e) {
            $this->io->warning('Could not fetch menus: ' . $e->getMessage());
            return;
        }

        if (empty($terms)) {
            $this->io->note('No menus found.');
            return;
        }

        $this->cleanupMenuItemJunk();

        foreach ($terms as $term) {
            $termId = (int) $term['term_id'];
            $rows   = $this->wpDb->fetchAllAssociative("
                SELECT p.ID, p.post_title, p.menu_order
                FROM {$this->wpPrefix}posts p
                INNER JOIN {$this->wpPrefix}term_relationships tr ON tr.object_id = p.ID
                INNER JOIN {$this->wpPrefix}term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                WHERE tt.taxonomy = 'nav_menu' AND tt.term_id = ?
                ORDER BY p.menu_order ASC
            ", [$termId]);

            $items = [];
            foreach ($rows as $row) {
                $itemId = (int) $row['ID'];
                $metas  = $this->wpDb->fetchAllAssociative(
                    "SELECT meta_key, meta_value FROM {$this->wpPrefix}postmeta WHERE post_id = ?",
                    [$itemId]
                );
                $meta = [];
                foreach ($metas as $m) {
                    $meta[$m['meta_key']] = $m['meta_value'];
                }
                $menuType = (string) ($meta['_menu_item_type'] ?? '');
                $object   = (string) ($meta['_menu_item_object'] ?? '');
                $objectId = (int) ($meta['_menu_item_object_id'] ?? 0);
                $obj      = $this->wpMenuObject($menuType, $object, $objectId);
                $items[]  = [
                    'wp_id'        => $itemId,
                    'parent'       => (int) ($meta['_menu_item_menu_item_parent'] ?? 0),
                    'title'        => $this->cleanImportedText((string) ($row['post_title'] ?? '')),
                    'menu_type'    => $menuType,
                    'object'       => $object,
                    'object_id'    => $objectId,
                    'object_title' => $obj['title'],
                    'object_slug'  => $obj['slug'],
                    'url'          => $this->cleanImportedText((string) ($meta['_menu_item_url'] ?? '')),
                    'target'       => (string) ($meta['_menu_item_target'] ?? ''),
                    'classes'      => $this->menuItemClasses((string) ($meta['_menu_item_classes'] ?? '')),
                    'order'        => (int) ($row['menu_order'] ?? 0),
                ];
            }

            $this->storeValeMenu(
                $this->cleanImportedText((string) $term['name']),
                (string) $term['slug'],
                $items
            );
        }

        $this->printMenuReport();
    }

    /** @return array{title: string, slug: string} */
    private function wpMenuObject(string $menuType, string $object, int $objectId): array
    {
        if ($objectId <= 0) {
            return ['title' => '', 'slug' => ''];
        }
        try {
            if ($menuType === 'taxonomy' && $object === 'category') {
                $row = $this->wpDb->fetchAssociative(
                    "SELECT name, slug FROM {$this->wpPrefix}terms WHERE term_id = ?",
                    [$objectId]
                );
                if (is_array($row)) {
                    return [
                        'title' => $this->cleanImportedText((string) ($row['name'] ?? '')),
                        'slug'  => (string) ($row['slug'] ?? ''),
                    ];
                }
                return ['title' => '', 'slug' => ''];
            }
            if (in_array($object, ['page', 'post', 'product'], true)) {
                $row = $this->wpDb->fetchAssociative(
                    "SELECT post_title, post_name FROM {$this->wpPrefix}posts WHERE ID = ?",
                    [$objectId]
                );
                if (is_array($row)) {
                    return [
                        'title' => $this->cleanImportedText((string) ($row['post_title'] ?? '')),
                        'slug'  => (string) ($row['post_name'] ?? ''),
                    ];
                }
            }
        } catch (\Exception) {
            // Fall through to empty
        }
        return ['title' => '', 'slug' => ''];
    }

    private function menuItemClasses(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        $list = $this->extractSerializedArray($raw);
        if (!is_array($list)) {
            return '';
        }
        $out = [];
        foreach ($list as $c) {
            $c = trim((string) $c);
            if ($c !== '') {
                $out[] = $c;
            }
        }
        return implode(' ', $out);
    }

    /**
     * WXR mode: menus have no <wp:term> blocks, so they are collected from
     * item <category domain="nav_menu"> tags. Object titles come from the
     * exported content items and category blocks of the same file.
     *
     * @param SimpleXMLElement[] $contentItems
     * @param SimpleXMLElement|null $categories
     */
    private function importMenusWxr(array $contentItems, $categories = null): void
    {
        $this->io->section('Importing Menus');

        $objectTitles = [];
        foreach ($contentItems as $item) {
            $wp       = $item->children(self::NS_WP);
            $postType = (string) $wp->post_type;
            if (!in_array($postType, ['post', 'page', 'product'], true)) {
                continue;
            }
            $objectTitles[(int) $wp->post_id] = [
                'title' => $this->cleanImportedText((string) $item->title),
                'slug'  => (string) $wp->post_name,
            ];
        }
        $catNames = [];
        if ($categories !== null) {
            foreach ($categories as $cat) {
                $wp                           = $cat->children(self::NS_WP);
                $catNames[(int) $wp->term_id] = [
                    'title' => $this->cleanImportedText((string) $cat->cat_name),
                    'slug'  => (string) $wp->category_nicename,
                ];
            }
        }

        $grouped = [];
        $names   = [];
        foreach ($contentItems as $item) {
            $wp = $item->children(self::NS_WP);
            if ((string) $wp->post_type !== 'nav_menu_item') {
                continue;
            }
            foreach ($item->category as $cat) {
                if ((string) $cat->attributes()->domain !== 'nav_menu') {
                    continue;
                }
                $nicename = (string) $cat->attributes()->nicename;
                if (($names[$nicename] ?? '') === '') {
                    $names[$nicename] = $this->cleanImportedText((string) $cat);
                }
                $meta     = $this->wxrParseMeta($wp->postmeta);
                $menuType = (string) ($meta['_menu_item_type'] ?? '');
                $object   = (string) ($meta['_menu_item_object'] ?? '');
                $objectId = (int) ($meta['_menu_item_object_id'] ?? 0);
                if ($menuType === 'taxonomy') {
                    $obj = $catNames[$objectId] ?? ['title' => '', 'slug' => ''];
                } else {
                    $obj = $objectTitles[$objectId] ?? ['title' => '', 'slug' => ''];
                }
                $grouped[$nicename][] = [
                    'wp_id'        => (int) $wp->post_id,
                    'parent'       => (int) ($meta['_menu_item_menu_item_parent'] ?? 0),
                    'title'        => $this->cleanImportedText((string) $item->title),
                    'menu_type'    => $menuType,
                    'object'       => $object,
                    'object_id'    => $objectId,
                    'object_title' => $obj['title'],
                    'object_slug'  => $obj['slug'],
                    'url'          => $this->cleanImportedText((string) ($meta['_menu_item_url'] ?? '')),
                    'target'       => (string) ($meta['_menu_item_target'] ?? ''),
                    'classes'      => $this->menuItemClasses((string) ($meta['_menu_item_classes'] ?? '')),
                    'order'        => (int) $wp->menu_order,
                ];
            }
        }

        if ($grouped === []) {
            $this->io->note('No menus found.');
            return;
        }

        $this->cleanupMenuItemJunk();

        foreach ($grouped as $nicename => $items) {
            usort($items, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);
            $menuName = ($names[$nicename] ?? '') !== '' ? $names[$nicename] : $nicename;
            $this->storeValeMenu($menuName, $nicename, $items);
        }

        $this->printMenuReport();
    }

    /**
     * Shared menu writer for both modes. Items resolve to typed references
     * (stable across slug changes); unresolvable targets become custom URLs.
     *
     * @param list<array{wp_id: int, parent: int, title: string, menu_type: string, object: string, object_id: int, object_title: string, object_slug: string, url: string, target: string, classes: string, order: int}> $items
     */
    private function storeValeMenu(string $name, string $slug, array $items): void
    {
        $slug = (string) Slug::fromString($slug !== '' ? $slug : $name);
        if ($name === '') {
            $name = $slug;
        }

        $existing = $this->valeDb->fetchOne(
            'SELECT id FROM ' . VL_DB_PREFIX . 'menus WHERE slug = ?',
            [$slug]
        );
        if ($existing) {
            $this->io->warning("Menu '{$name}' skipped: slug '{$slug}' already exists.");
            return;
        }

        if (!$this->dryRun) {
            $this->valeDb->insert(VL_DB_PREFIX . 'menus', [
                'name'       => $name,
                'slug'       => $slug,
                'language'   => $this->defaultLocale,
                'location'   => null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $menuId = (int) $this->valeDb->lastInsertId();
        } else {
            $menuId = 0;
        }

        $rows = [];
        foreach ($items as $ni) {
            $label = $ni['title'] !== '' ? $ni['title'] : $ni['object_title'];
            if ($label === '') {
                $this->skippedMenuItems[] = [
                    'menu'   => $name,
                    'title'  => '(item ' . $ni['wp_id'] . ')',
                    'reason' => 'Empty label and unresolvable target',
                ];
                continue;
            }
            $target = $this->mapMenuItemTarget($ni);
            if ($target === null) {
                $this->skippedMenuItems[] = ['menu' => $name, 'title' => $label, 'reason' => 'Unresolvable target and no usable URL'];
                continue;
            }
            if ($target['type'] === 'custom') {
                $this->menuLinkCheck[] = ['menu' => $name, 'label' => $label, 'url' => (string) $target['url']];
            }
            $rows[] = [
                'ni'     => $ni,
                'label'  => $label,
                'target' => $target,
            ];
        }

        $idMap = [];
        if (!$this->dryRun) {
            foreach ($rows as $row) {
                $this->valeDb->insert(VL_DB_PREFIX . 'menu_items', [
                    'menu_id'      => $menuId,
                    'parent_id'    => null,
                    'type'         => $row['target']['type'],
                    'label'        => $row['label'],
                    'url'          => $row['target']['url'],
                    'target'       => strtolower(trim($row['ni']['target'])) === '_blank' ? '_blank' : '_self',
                    'reference_id' => $row['target']['referenceId'],
                    'sort_order'   => $row['ni']['order'],
                    'css_class'    => $row['ni']['classes'] !== '' ? $row['ni']['classes'] : null,
                ]);
                $idMap[$row['ni']['wp_id']] = (int) $this->valeDb->lastInsertId();
            }
            foreach ($rows as $row) {
                $parentWp = $row['ni']['parent'];
                if ($parentWp <= 0 || !isset($idMap[$row['ni']['wp_id']], $idMap[$parentWp])) {
                    continue;
                }
                $this->valeDb->update(
                    VL_DB_PREFIX . 'menu_items',
                    ['parent_id' => $idMap[$parentWp]],
                    ['id'        => $idMap[$row['ni']['wp_id']]]
                );
            }
        }

        $this->importedMenus[] = ['name' => $name, 'slug' => $slug, 'items' => count($rows)];
    }

    /**
     * @param array{menu_type: string, object: string, object_id: int, object_slug: string, url: string} $ni
     * @return array{type: string, referenceId: int|null, url: string|null}|null null = unusable, skip the item
     */
    private function mapMenuItemTarget(array $ni): ?array
    {
        $menuType = $ni['menu_type'];
        $object   = $ni['object'];
        $oid      = $ni['object_id'];

        if ($menuType === 'post_type' && $object === 'page' && isset($this->postMap[$oid])) {
            return ['type' => 'page', 'referenceId' => $this->postMap[$oid], 'url' => null];
        }
        if ($menuType === 'post_type' && $object === 'post' && isset($this->postMap[$oid])) {
            return ['type' => 'post', 'referenceId' => $this->postMap[$oid], 'url' => null];
        }
        if ($menuType === 'post_type' && $object === 'product' && isset($this->wooProductMap[$oid])) {
            return ['type' => 'product', 'referenceId' => $this->wooProductMap[$oid], 'url' => null];
        }
        if ($menuType === 'taxonomy' && $object === 'category') {
            $ref = $this->termIdMap[$oid] ?? $this->termMap[$oid] ?? null;
            if ($ref !== null) {
                return ['type' => 'category', 'referenceId' => $ref, 'url' => null];
            }
        }
        if ($menuType === 'custom' && $ni['url'] !== '') {
            return ['type' => 'custom', 'referenceId' => null, 'url' => $ni['url']];
        }
        // Best-effort fallback: point at the WP slug path when known.
        $slug = $ni['object_slug'];
        if ($slug !== '') {
            if ($object === 'product') {
                $prefix = $this->valeSetting('routing_shop_prefix', '/shop');
            } elseif ($object === 'category' && $menuType === 'taxonomy') {
                $prefix = $this->valeSetting('routing_category_prefix', '/category');
            } else {
                $prefix = $this->valeSetting('routing_page_prefix', '');
            }
            $url = ($prefix !== '' ? '/' . ltrim($prefix, '/') : '') . '/' . $slug;
            return ['type' => 'custom', 'referenceId' => null, 'url' => $url];
        }
        return null;
    }

    private function valeSetting(string $key, string $default): string
    {
        $this->routingValidator();
        if ($this->valeSettings === null) {
            return $default;
        }
        return (string) ($this->valeSettings->get($key, $default) ?? $default);
    }

    /**
     * Remove stray nav_menu_item rows left by older imports (WXR used to
     * store menu items as posts). Count-only in dry-run mode.
     */
    private function cleanupMenuItemJunk(): void
    {
        try {
            $count = (int) $this->valeDb->fetchOne(
                'SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'posts WHERE content_type = ?',
                ['nav_menu_item']
            );
        } catch (\Exception) {
            return;
        }
        if ($count <= 0) {
            return;
        }
        if ($this->dryRun) {
            $this->io->note("Dry run: would remove {$count} stray nav_menu_item post(s).");
            return;
        }
        $this->valeDb->delete(VL_DB_PREFIX . 'posts', ['content_type' => 'nav_menu_item']);
        $this->io->text("Removed {$count} stray nav_menu_item post(s).");
    }

    private function printMenuReport(): void
    {
        if ($this->importedMenus !== []) {
            $this->io->table(
                ['Menu', 'Slug', 'Items'],
                array_map(
                    static fn (array $m): array => [$m['name'], $m['slug'], $m['items']],
                    $this->importedMenus
                )
            );
        }
        if ($this->skippedMenuItems !== []) {
            $this->io->warning(sprintf('Skipped %d menu item(s):', count($this->skippedMenuItems)));
            $this->io->table(
                ['Menu', 'Item', 'Reason'],
                array_map(
                    static fn (array $s): array => [$s['menu'], $s['title'], $s['reason']],
                    $this->skippedMenuItems
                )
            );
        }
        if ($this->menuLinkCheck !== []) {
            $knownSlugs = [];
            try {
                foreach ($this->valeDb->fetchAllAssociative('SELECT DISTINCT slug FROM ' . VL_DB_PREFIX . 'posts') as $row) {
                    $knownSlugs[(string) ($row['slug'] ?? '')] = true;
                }
            } catch (\Exception) {
                // Leave the set empty; every link reports as unverified
            }
            $knownFirst = [];
            foreach (RoutingPrefixTypes::DEFAULT_PREFIXES as $prefix) {
                $first = explode('/', ltrim($prefix, '/'))[0] ?? '';
                if ($first !== '') {
                    $knownFirst[$first] = true;
                }
            }
            $rows = [];
            foreach ($this->menuLinkCheck as $link) {
                $rows[] = [$link['menu'], $link['label'], $link['url'], $this->menuLinkKnown($link['url'], $knownSlugs, $knownFirst)];
            }
            $this->io->table(['Menu', 'Label', 'URL', 'Target known'], $rows);
        }
        $slugs = array_column($this->importedMenus, 'slug');
        $tips  = [];
        if (in_array('top-menu', $slugs, true)) {
            $tips[] = 'Top Menu → primary';
        }
        if (in_array('social-media', $slugs, true)) {
            $tips[] = 'Social Media → footer';
        }
        if ($tips !== []) {
            $this->io->text('Suggested locations (assign in admin Appearance → Menus; locations vary by theme): ' . implode(', ', $tips));
        }
    }

    /**
     * @param array<string, true> $knownSlugs
     * @param array<string, true> $knownFirst
     */
    private function menuLinkKnown(string $url, array $knownSlugs, array $knownFirst): string
    {
        $clean = (string) preg_replace('/[?#].*$/', '', $url);
        if (str_starts_with($clean, 'http://') || str_starts_with($clean, 'https://')) {
            return 'external';
        }
        $segments = array_values(array_filter(explode('/', $clean)));
        if ($segments === []) {
            return 'yes';
        }
        $last  = end($segments);
        $first = $segments[0];
        if (isset($knownSlugs[$last]) || isset($knownFirst[$first])) {
            return 'yes';
        }
        return 'no';
    }

    private function updateContentMediaLinks(): void
    {
        // Only update when posts/content or WooCommerce are being imported
        if (!$this->importAll && !$this->importPostsOnly && !$this->importWooOnly) {
            return;
        }

        if ($this->importedMediaUrls === []) {
            return;
        }

        $plan = WpMediaUrlRewriter::plan($this->importedMediaUrls, __app_base_path());
        if ($plan['exact'] === [] && $plan['bases'] === []) {
            return;
        }

        $this->io->text('Updating media links in content...');

        // Update post/page/CPT bodies and excerpts
        $posts = $this->valeDb->fetchAllAssociative(
            'SELECT id, body, excerpt FROM ' . VL_DB_PREFIX . 'posts'
        );
        foreach ($posts as $post) {
            $changes = [];
            if (!empty($post['body'])) {
                $rewritten = WpMediaUrlRewriter::apply(
                    (string) $post['body'],
                    $plan['exact'],
                    $plan['bases']
                );
                if ($rewritten !== $post['body']) {
                    $changes['body'] = $rewritten;
                }
            }
            if (!empty($post['excerpt'])) {
                $rewritten = WpMediaUrlRewriter::apply(
                    (string) $post['excerpt'],
                    $plan['exact'],
                    $plan['bases']
                );
                if ($rewritten !== $post['excerpt']) {
                    $changes['excerpt'] = $rewritten;
                }
            }
            if ($changes !== []) {
                $this->valeDb->update(VL_DB_PREFIX . 'posts', $changes, ['id' => $post['id']]);
            }
        }

        // Update product description and short description
        $products = $this->valeDb->fetchAllAssociative(
            'SELECT id, description, short_description FROM ' . VL_DB_PREFIX . 'products'
        );
        foreach ($products as $product) {
            $changes = [];
            if (!empty($product['description'])) {
                $rewritten = WpMediaUrlRewriter::apply(
                    (string) $product['description'],
                    $plan['exact'],
                    $plan['bases']
                );
                if ($rewritten !== $product['description']) {
                    $changes['description'] = $rewritten;
                }
            }
            if (!empty($product['short_description'])) {
                $rewritten = WpMediaUrlRewriter::apply(
                    (string) $product['short_description'],
                    $plan['exact'],
                    $plan['bases']
                );
                if ($rewritten !== $product['short_description']) {
                    $changes['short_description'] = $rewritten;
                }
            }
            if ($changes !== []) {
                $this->valeDb->update(
                    VL_DB_PREFIX . 'products',
                    $changes,
                    ['id' => $product['id']]
                );
            }
        }

        $this->backfillMissingVariantFiles($plan['bases']);
    }

    /**
     * WordPress size-variant safety net, runs after URL rewriting:
     *  - download mode: fetch variant files referenced in content but missing
     *    on disk (reconstructing the source URL from the rewrite bases);
     *  - always: point remaining missing-variant references at the original
     *    file when it exists (a full-size image beats a broken one).
     *
     * File downloads respect dry-run; DB updates roll back with the import
     * transaction.
     *
     * @param array<string, string> $bases source base URL => local base
     */
    private function backfillMissingVariantFiles(array $bases): void
    {
        $uploadsDir = dirname(__DIR__, 2) . '/storage/uploads';
        $localBase  = rtrim(__app_base_path(), '/') . WpMediaUrlRewriter::LOCAL_BASE;

        $targets = [];
        try {
            foreach ($this->valeDb->fetchAllAssociative('SELECT id, body, excerpt FROM ' . VL_DB_PREFIX . 'posts') as $row) {
                $targets[] = [
                    'table'  => VL_DB_PREFIX . 'posts',
                    'id'     => $row['id'],
                    'fields' => ['body' => (string) ($row['body'] ?? ''), 'excerpt' => (string) ($row['excerpt'] ?? '')],
                ];
            }
            foreach ($this->valeDb->fetchAllAssociative('SELECT id, description, short_description FROM ' . VL_DB_PREFIX . 'products') as $row) {
                $targets[] = [
                    'table'  => VL_DB_PREFIX . 'products',
                    'id'     => $row['id'],
                    'fields' => ['description' => (string) ($row['description'] ?? ''), 'short_description' => (string) ($row['short_description'] ?? '')],
                ];
            }
        } catch (\Throwable $e) {
            $this->io->warning('Variant backfill skipped: ' . $e->getMessage());
            return;
        }

        $oldBases = array_keys($bases);
        usort($oldBases, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $downloaded = 0;
        $normalized = 0;
        foreach ($targets as $target) {
            $changes = [];
            foreach ($target['fields'] as $field => $text) {
                if ($text === '' || !preg_match_all('~[^\s"\'()<>]+?-(?:\d+x\d+|scaled)\.[A-Za-z0-9]{2,5}(?:[?#][^\s"\'()<>]*)?~i', $text, $m)) {
                    continue;
                }
                foreach (array_unique($m[0]) as $variantUrl) {
                    $rel = $this->uploadsRelativePath($variantUrl, $localBase);
                    if ($rel === null || is_file($uploadsDir . '/' . $rel)) {
                        continue;
                    }
                    if (!$this->dryRun && $this->downloadMedia && $this->downloadVariantFromBases($rel, $oldBases) !== null) {
                        $downloaded++;
                        continue;
                    }
                    $originalUrl = WpMediaUrlRewriter::originalForVariant($variantUrl);
                    $originalRel = $originalUrl === null ? null : $this->uploadsRelativePath($originalUrl, $localBase);
                    if ($originalRel !== null && is_file($uploadsDir . '/' . $originalRel)) {
                        $text = str_replace($variantUrl, $originalUrl, $text);
                        $normalized++;
                    }
                }
                if ($text !== $target['fields'][$field]) {
                    $changes[$field] = $text;
                }
            }
            if ($changes !== []) {
                $this->valeDb->update($target['table'], $changes, ['id' => $target['id']]);
            }
        }

        if ($downloaded > 0) {
            $this->io->text(sprintf('Downloaded %d missing image variant file(s).', $downloaded));
        }
        if ($normalized > 0) {
            $this->io->text(sprintf('Pointed %d missing image variant reference(s) at the original file.', $normalized));
        }
    }

    /**
     * Try each known source base (longest first) for a missing variant file.
     * Returns the stored path on success, null otherwise.
     *
     * @param list<string> $oldBases
     */
    private function downloadVariantFromBases(string $relativePath, array $oldBases): ?string
    {
        foreach ($oldBases as $oldBase) {
            $stored = $this->importDownloadAttachment(rtrim($oldBase, '/') . '/' . $relativePath);
            if ($stored !== null) {
                return $stored;
            }
        }
        return null;
    }

    /**
     * Map a rewritten local URL back to its path under storage/uploads.
     * Remote URLs and paths outside the uploads base return null.
     */
    private function uploadsRelativePath(string $url, string $localBase): ?string
    {
        $clean = (string) preg_replace('/[?#].*$/', '', $url);
        if (str_starts_with($clean, 'http://') || str_starts_with($clean, 'https://')) {
            return null;
        }
        if (!str_starts_with($clean, $localBase)) {
            return null;
        }
        $rel = substr($clean, strlen($localBase));
        if ($rel === '' || $rel === false || str_contains($rel, '..')) {
            return null;
        }
        return $rel;
    }

    private function importSeoMeta(int $wpPostId, int $valeEntityId, string $valeEntityType): void
    {
        $metas = $this->wpDb->fetchAllAssociative("
            SELECT meta_key, meta_value FROM {$this->wpPrefix}postmeta
            WHERE post_id = ? AND meta_key IN (
                '_yoast_wpseo_title', '_yoast_wpseo_metadesc',
                '_yoast_wpseo_opengraph-title', '_yoast_wpseo_opengraph-description', '_yoast_wpseo_opengraph-image',
                '_yoast_wpseo_twitter-title', '_yoast_wpseo_twitter-description',
                '_yoast_wpseo_canonical', '_yoast_wpseo_meta-robots', '_yoast_wpseo_focuskw',
                'rank_math_title', 'rank_math_description',
                'rank_math_facebook_title', 'rank_math_facebook_description', 'rank_math_facebook_image',
                'rank_math_twitter_title', 'rank_math_twitter_description', 'rank_math_twitter_image',
                'rank_math_canonical_url', 'rank_math_robots', 'rank_math_focus_keyword'
            )
        ", [$wpPostId]);

        if (empty($metas)) {
            return;
        }

        $seoData = [
            'entity_type' => $valeEntityType,
            'entity_id'   => $valeEntityId,
            'created_at'  => date('Y-m-d H:i:s'),
        ];

        foreach ($metas as $m) {
            $key   = $m['meta_key'];
            $value = $m['meta_value'];

            match ($key) {
                '_yoast_wpseo_title', 'rank_math_title'
                    => $seoData['meta_title'] = $value,

                '_yoast_wpseo_metadesc', 'rank_math_description'
                    => $seoData['meta_description'] = $value,

                '_yoast_wpseo_opengraph-title', 'rank_math_facebook_title'
                    => $seoData['og_title'] = $value,

                '_yoast_wpseo_opengraph-description', 'rank_math_facebook_description'
                    => $seoData['og_description'] = $value,

                '_yoast_wpseo_canonical', 'rank_math_canonical_url'
                    => $seoData['canonical_url'] = $value,

                '_yoast_wpseo_meta-robots', 'rank_math_robots'
                    => $seoData['robots'] = $value,

                '_yoast_wpseo_focuskw', 'rank_math_focus_keyword'
                    => $seoData['focus_keyword'] = $value,

                '_yoast_wpseo_opengraph-image', 'rank_math_facebook_image'
                    => $seoData['og_image_id'] = isset($this->mediaMap[(int) $value]) ? $this->mediaMap[(int) $value] : null,

                '_yoast_wpseo_twitter-title', 'rank_math_twitter_title'
                    => $seoData['twitter_card_type'] = !empty($value) ? 'summary_large_image' : 'summary',

                default => null,
            };
        }

        if (isset($seoData['og_image_id']) && !isset($seoData['twitter_card_type'])) {
            $seoData['twitter_card_type'] = 'summary_large_image';
        }

        if (!isset($seoData['meta_title']) && !isset($seoData['meta_description']) && !isset($seoData['og_title'])) {
            return;
        }

        $this->valeDb->insert(VL_DB_PREFIX . 'seo_meta', $seoData);
    }

    private function validateWpTables(): bool
    {
        $missing = [];

        foreach (self::REQUIRED_WP_TABLES as $table) {
            $tableName = $this->wpPrefix . $table;
            try {
                if (!$this->tableExists($this->wpDb, $tableName)) {
                    $missing[] = $tableName;
                }
            } catch (\Exception $e) {
                $this->io->error('Could not inspect WordPress table ' . $tableName . ': ' . $e->getMessage());
                return false;
            }
        }

        if (!empty($missing)) {
            $this->io->error('The following WordPress tables are missing: ' . implode(', ', $missing));
            $this->io->note('Ensure your SQL dump contains these tables or check your --wp-prefix.');
            return false;
        }

        return true;
    }

    /**
     * Warn about optional tables the selected modules need, so the user knows
     * which steps are skipped. Modules that were not selected never trigger a
     * warning — a WooCommerce-less import stays quiet about WooCommerce tables.
     */
    private function warnMissingOptionalTables(): void
    {
        $missing = [];

        foreach (self::OPTIONAL_WP_TABLES as $table => $purpose) {
            if (!$this->isOptionalTableRelevant($table)) {
                continue;
            }

            $tableName = $this->wpPrefix . $table;
            try {
                $exists = $this->tableExists($this->wpDb, $tableName);
            } catch (\Exception $e) {
                $missing[] = '  ' . $tableName . ' → ' . $purpose . ' (could not inspect: ' . $e->getMessage() . ')';
                continue;
            }

            if (!$exists) {
                $missing[] = '  ' . $tableName . ' → ' . $purpose;
            }
        }

        if ($missing === []) {
            return;
        }

        $this->io->warning('Optional WordPress tables are missing — related steps will be skipped:');
        $this->io->listing($missing);
    }

    private function isOptionalTableRelevant(string $table): bool
    {
        $wooOrderTables = [
            'wc_orders',
            'wc_order_addresses',
            'wc_orders_meta',
            'wc_order_items',
            'woocommerce_order_items',
            'woocommerce_order_itemmeta',
        ];

        if (in_array($table, $wooOrderTables, true)) {
            return $this->importAll || $this->importWooOnly;
        }

        return match ($table) {
            'options'              => $this->importAll || $this->importWooOnly || $this->importPostsOnly,
            'comments'             => $this->importAll || $this->importPostsOnly || $this->importWooOnly,
            'mailpoet_subscribers' => $this->importAll || $this->importPostsOnly || $this->importSubscribersOnly,
            default                => false,
        };
    }

    private function ensureContentTypeExists(string $slug, string $name): void
    {
        $exists = $this->valeDb->fetchOne('SELECT id FROM ' . VL_DB_PREFIX . 'content_types WHERE slug = ?', [$slug]);
        if (!$exists) {
            $this->valeDb->insert(VL_DB_PREFIX . 'content_types', [
                'slug'       => $slug,
                'name'       => $name,
                'is_system'  => 0,
                'is_active'  => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'supports'   => json_encode(['title', 'editor', 'thumbnail', 'excerpt', 'comments', 'revisions']),
            ]);
        }
    }

    private function openSqlFile(string $filePath)
    {
        if (str_ends_with($filePath, '.gz')) {
            $handle = gzopen($filePath, 'r');
        } else {
            $handle = fopen($filePath, 'r');
        }
        return $handle;
    }

    private function readSqlLine($handle): string|false
    {
        if (str_contains(get_resource_type($handle), 'gz')) {
            return gzgets($handle);
        }
        return fgets($handle);
    }

    private function closeSqlFile($handle): void
    {
        if (str_contains(get_resource_type($handle), 'gz')) {
            gzclose($handle);
        } else {
            fclose($handle);
        }
    }

    private function loadSqlDump(string $filePath): bool
    {
        $params = $this->valeDb->getParams();
        $driver = $params['driver'] ?? '';
        if (str_contains($driver, 'sqlite')) {
            $this->io->error('SQL dumps cannot be used with SQLite. Use --wp-db for a live database or --file for a WXR/XML export.');
            return false;
        }

        $handle = $this->openSqlFile($filePath);
        if (!$handle) {
            $this->io->error("Could not open SQL dump file: {$filePath}");
            return false;
        }

        $this->io->text('Executing SQL dump queries...');

        $progressBar = new ProgressBar($this->io, $this->countFileLines($filePath));
        $progressBar->setRedrawFrequency(200);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %message%');
        $progressBar->setMessage('Executing...');
        $progressBar->start();

        $query   = '';
        $skipped = 0;
        while (($line = $this->readSqlLine($handle)) !== false) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '/*')) {
                $skipped++;
                continue;
            }

            $query .= $line;

            if (preg_match('/;\s*$/', $trimmed)) {
                try {
                    $this->valeDb->executeStatement($query);
                } catch (\Exception $e) {
                    $message = $e->getMessage();
                    if (str_contains($message, 'Multiple primary key defined') || str_contains($message, 'Duplicate key name')
                                                                               || str_contains($message, 'already exists') || str_contains($message, 'Duplicate entry')
                                                                               || str_contains($message, 'already exist')) {
                        $this->sqlDumpSuppressed++;
                    } else {
                        $this->sqlDumpWarnings[] = $message;
                    }
                }
                $query = '';
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->closeSqlFile($handle);
        $this->io->newLine(2);
        $this->io->text("Executed SQL dump. Skipped {$skipped} comment/empty lines.");

        if ($this->sqlDumpSuppressed > 0) {
            $this->io->text("Suppressed {$this->sqlDumpSuppressed} benign dump warnings (duplicate keys/objects).");
        }

        return true;
    }

    private function countFileLines(string $filePath): int
    {
        $count  = 0;
        $handle = $this->openSqlFile($filePath);
        if (!$handle) {
            return 0;
        }
        while (($line = $this->readSqlLine($handle)) !== false) {
            $count++;
        }
        $this->closeSqlFile($handle);
        return $count;
    }

    private function getAllTableNames(): array
    {
        return $this->valeDb->createSchemaManager()->listTableNames();
    }

    private function cleanupWordPressTables(): void
    {
        $this->io->text('Cleaning up WordPress tables...');
        $allTables = $this->getAllTableNames();

        $dropped = 0;
        foreach ($allTables as $tableName) {
            if (str_starts_with($tableName, $this->wpPrefix)) {
                try {
                    $quoted = $this->valeDb->quoteIdentifier($tableName);
                    $this->valeDb->executeStatement("DROP TABLE IF EXISTS {$quoted}");
                    $this->io->text("  Dropped: {$tableName}");
                    $dropped++;
                } catch (\Exception) {
                    // Ignore
                }
            }
        }
        $this->io->info("Dropped {$dropped} WordPress tables.");
    }

    private function cleanupImportedWordPressTables(): void
    {
        $this->io->text('Cleaning up WordPress tables introduced by the import...');
        $currentTables = $this->getAllTableNames();

        $newTables = array_diff($currentTables, $this->preDumpTables);

        $dropped = 0;
        foreach ($newTables as $tableName) {
            if (str_starts_with($tableName, $this->wpPrefix)) {
                try {
                    $quoted = $this->valeDb->quoteIdentifier($tableName);
                    $this->valeDb->executeStatement("DROP TABLE IF EXISTS {$quoted}");
                    $this->io->text("  Dropped: {$tableName}");
                    $dropped++;
                } catch (\Exception) {
                    // Ignore
                }
            }
        }
        $this->io->info("Dropped {$dropped} WordPress tables.");
    }

    private function fixDestinationSlugs(): void
    {
        $this->io->section('Fixing destination slugs');

        $prefix = VL_DB_PREFIX;

        $targets = [
            ['table' => $prefix . 'posts',               'column' => 'slug', 'label' => 'Posts & pages'],
            ['table' => $prefix . 'categories',          'column' => 'slug', 'label' => 'Categories'],
            ['table' => $prefix . 'tags',                'column' => 'slug', 'label' => 'Tags'],
            ['table' => $prefix . 'content_types',       'column' => 'slug', 'label' => 'Content types'],
            ['table' => $prefix . 'menus',               'column' => 'slug', 'label' => 'Menus'],
            ['table' => $prefix . 'product_categories',  'column' => 'slug', 'label' => 'Product categories'],
            ['table' => $prefix . 'products',            'column' => 'slug', 'label' => 'Products'],
            ['table' => $prefix . 'forms',               'column' => 'slug', 'label' => 'Forms'],
        ];

        $totalFixed = 0;

        foreach ($targets as $target) {
            try {
                $exists = $this->tableExists($this->valeDb, $target['table']);
                if (!$exists) {
                    continue;
                }

                $rows = $this->valeDb->fetchAllAssociative(
                    "SELECT id, " . $this->valeDb->quoteIdentifier($target['column'])
                    . " FROM " . $this->valeDb->quoteIdentifier($target['table'])
                );
            } catch (\Exception) {
                continue;
            }

            $fixed = 0;

            foreach ($rows as $row) {
                $slugValue = $row[$target['column']] ?? '';

                try {
                    new Slug($slugValue);
                    continue;
                } catch (DomainException) {
                    // Invalid — sanitize below
                }

                $cleanSlug = (string) Slug::fromString($slugValue);
                $finalSlug = $cleanSlug;
                $suffix    = 1;
                while ($this->slugExists($target['table'], $target['column'], $finalSlug, (int) $row['id'])) {
                    $finalSlug = $cleanSlug . '-' . $suffix;
                    $suffix++;
                }

                try {
                    $this->valeDb->update(
                        $target['table'],
                        [$target['column'] => $finalSlug],
                        ['id'              => (int) $row['id']]
                    );
                    $fixed++;
                } catch (\Exception) {
                    // Skip on update failure
                }
            }

            if ($fixed > 0) {
                $this->io->text("  {$target['label']}: {$fixed} slug(s) fixed");
            }
            $totalFixed += $fixed;
        }

        if ($totalFixed === 0) {
            $this->io->note('No invalid slugs found.');
        } else {
            $this->io->success("Fixed {$totalFixed} slug(s) across all tables.");
        }
    }

    private function tableExists(Connection $db, string $tableName): bool
    {
        return in_array(strtolower($tableName), array_map('strtolower', $db->createSchemaManager()->listTableNames()), true);
    }

    private function slugExists(string $table, string $column, string $slug, int $excludeId): bool
    {
        $quotedTable = $this->valeDb->quoteIdentifier($table);
        $quotedCol   = $this->valeDb->quoteIdentifier($column);
        $result      = $this->valeDb->fetchOne(
            "SELECT COUNT(*) FROM {$quotedTable} WHERE {$quotedCol} = ? AND id != ?",
            [$slug, $excludeId]
        );
        return (int) $result > 0;
    }

    // ══════════════════════════════════════════════════════════════════════
    //  --cleanup-vale
    // ══════════════════════════════════════════════════════════════════════

    private function cleanupValeTables(): void
    {
        $this->io->section('Cleaning up WordPress tables from Vale database');

        $prefix = $this->wpPrefix;
        $tables = [
            'commentmeta', 'comments', 'links', 'options', 'postmeta', 'posts',
            'term_relationships', 'term_taxonomy', 'termmeta', 'terms', 'usermeta', 'users',
            'mailpoet_subscribers', 'wc_product_meta_lookup', 'wc_reserved_stock',
            'woocommerce_payment_tokenmeta', 'woocommerce_payment_tokens',
            'woocommerce_order_itemmeta', 'woocommerce_order_items',
            'wc_orders', 'wc_orders_meta'
        ];

        $dropped = 0;
        foreach ($tables as $table) {
            $tableName = $prefix . $table;
            try {
                $exists = $this->tableExists($this->valeDb, $tableName);
                if ($exists) {
                    if (!$this->dryRun) {
                        $this->valeDb->executeStatement("DROP TABLE IF EXISTS " . $this->valeDb->quoteIdentifier($tableName));
                    }
                    $dropped++;
                    $this->io->text("  Dropped: {$tableName}");
                }
            } catch (\Exception) {
                // Ignore
            }
        }

        if ($dropped === 0) {
            $this->io->note('No WordPress tables found in Vale database.');
        } else {
            $this->io->success("Dropped {$dropped} WordPress table(s)." . ($this->dryRun ? ' (dry run)' : ''));
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    //  WXR mode
    // ══════════════════════════════════════════════════════════════════════

    private function executeWxrMode(string $filePath, InputInterface $input): int
    {
        if (!file_exists($filePath)) {
            $this->io->error("WXR file not found: {$filePath}");
            return Command::FAILURE;
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($filePath);
        if (!$xml) {
            $this->io->error('Invalid WXR XML file.');
            foreach (libxml_get_errors() as $error) {
                $this->io->text("  XML error: {$error->message}");
            }
            libxml_clear_errors();
            return Command::FAILURE;
        }

        if ($this->dryRun) {
            $this->valeDb->beginTransaction();
            $this->io->note('Dry run — changes will be rolled back at the end.');
        }

        try {
            $channel = $xml->channel;
            $wp      = $channel->children(self::NS_WP);
            $items   = $channel->item;

            if ($this->importAll || $this->importUsersOnly || $this->importWooOnly) {
                $this->wxrImportAuthors($wp->author);
            }
            if ($this->importAll || $this->importPostsOnly || $this->importWooOnly) {
                $this->wxrImportTerms($wp->category, $wp->tag, $wp->term);
                $this->wxrResolveTermParents();
            }

            [$mediaItems, $contentItems] = $this->wxrSplitItems($items);
            $this->wxrImportMedia($mediaItems);

            if ($this->importSeoOnly) {
                $this->wxrImportSeoOnly($contentItems);
            } else {
                $this->wxrImportContentItems($contentItems);
            }

            if ($this->importAll || $this->importPostsOnly) {
                $this->importMenusWxr($contentItems, $wp->category);
            }

            // Same media URL rewriting as DB mode (WXR bodies keep the original
            // wp-content/uploads references until media links are resolved).
            $this->updateContentMediaLinks();

            $this->resolveWxrPolylangTranslations();

            $this->importWooCommerceCurrency();
            $this->importWooCommerceShopSettings();

            if ($input->getOption('fix-slugs')) {
                $this->fixDestinationSlugs();
            }

            if ($input->getOption('cleanup-vale')) {
                $this->cleanupValeTables();
            }

            $this->backfillMediaVariants();
            $this->printWooDigitalReport();
            $this->printImportSummary($input, true);

            $this->flushPageCache($this->pageCache, $this->dryRun, $this->io);

            $this->io->success('WXR import completed successfully!' . ($this->dryRun ? ' (dry run)' : ''));

            return Command::SUCCESS;
        } catch (\Exception $e) {
            if ($this->dryRun && $this->valeDb->isTransactionActive()) {
                $this->valeDb->rollBack();
            }
            $this->io->error('WXR import failed: ' . $e->getMessage());
            return Command::FAILURE;
        } finally {
            if ($this->dryRun && $this->valeDb->isTransactionActive()) {
                try {
                    $this->valeDb->rollBack();
                    $this->io->note('Dry run — all changes rolled back.');
                } catch (\Exception $e) {
                    // Ignore rollback errors if transaction state is inconsistent
                }
            }
        }
    }

    private function wxrSplitItems(SimpleXMLElement $items): array
    {
        $media   = [];
        $content = [];
        foreach ($items as $item) {
            $wp       = $item->children(self::NS_WP);
            $postType = (string) $wp->post_type;
            if ($postType === 'attachment') {
                $media[] = $item;
            } else {
                $content[] = $item;
            }
        }
        return [$media, $content];
    }

    private function wxrImportAuthors(SimpleXMLElement $authors): void
    {
        $this->io->section('Importing Authors');
        $count = $authors->count();
        if ($count === 0) {
            $this->io->note('No authors found.');
            return;
        }
        $this->io->text(sprintf('Found %d author(s).', $count));

        $progressBar = new ProgressBar($this->io, $count);
        foreach ($authors as $author) {
            try {
                $email       = (string) $author->children(self::NS_WP)->author_email;
                $login       = (string) $author->children(self::NS_WP)->author_login;
                $displayName = (string) $author->children(self::NS_WP)->author_display_name;

                $existing = $this->valeDb->fetchAssociative(
                    'SELECT id FROM ' . VL_DB_PREFIX . 'users WHERE email = ?',
                    [$email]
                );

                if ($existing) {
                    $this->userMap[$login] = (int) $existing['id'];
                } else {
                    if (!$this->dryRun) {
                        $this->valeDb->insert(VL_DB_PREFIX . 'users', [
                            'email'      => $email,
                            'password'   => $this->hasher->hash(bin2hex(random_bytes(16))),
                            'name'       => $displayName ?: $login,
                            'created_at' => date('Y-m-d H:i:s'),
                            'is_active'  => 0,
                        ]);
                        $newId = (int) $this->valeDb->lastInsertId();

                        $subscriberRole = $this->valeDb->fetchOne(
                            'SELECT id FROM ' . VL_DB_PREFIX . 'roles WHERE slug = ?',
                            ['subscriber']
                        );
                        if ($subscriberRole) {
                            $this->valeDb->insert(VL_DB_PREFIX . 'user_roles', [
                                'user_id' => $newId,
                                'role_id' => $subscriberRole,
                            ]);
                        }
                    }
                    $this->userMap[$login] = 0;
                }
            } catch (\Exception $e) {
                $this->io->warning("Failed to import author {$login}: " . $e->getMessage());
            }
            $progressBar->advance();
        }
        $progressBar->finish();
        $this->io->newLine(2);

        if ($this->dryRun) {
            foreach ($authors as $author) {
                $login = (string) $author->children(self::NS_WP)->author_login;
                if (!isset($this->userMap[$login])) {
                    continue;
                }
                $email    = (string) $author->children(self::NS_WP)->author_email;
                $existing = $this->valeDb->fetchAssociative(
                    'SELECT id FROM ' . VL_DB_PREFIX . 'users WHERE email = ?',
                    [$email]
                );
                $this->userMap[$login] = $existing ? (int) $existing['id'] : 0;
            }
        }
    }

    private function wxrImportTerms(SimpleXMLElement $categories, SimpleXMLElement $tags, SimpleXMLElement $terms): void
    {
        $this->io->section('Importing Categories, Tags and Terms');
        $totalTerms = $categories->count() + $tags->count() + $terms->count();
        if ($totalTerms === 0) {
            $this->io->note('No terms found.');
            return;
        }
        $this->io->text(sprintf('Found %d term(s).', $totalTerms));

        $progressBar = new ProgressBar($this->io, $totalTerms);

        foreach (['category' => $categories, 'post_tag' => $tags] as $taxonomy => $elements) {
            foreach ($elements as $el) {
                $this->wxrImportSingleTerm($el, $taxonomy, $progressBar);
            }
        }

        foreach ($terms as $term) {
            $taxonomy = (string) $term->children(self::NS_WP)->term_taxonomy;
            $this->wxrImportSingleTerm($term, $taxonomy, $progressBar);
        }

        $progressBar->finish();
        $this->io->newLine(2);
    }

    /**
     * Resolve WXR term parent-child links after every term has been imported.
     * Parents may be exported after their children, and the WP slug of an
     * existing Vale term may differ from its WP nicename, so both slug maps
     * and the stored Vale slug are consulted.
     */
    private function wxrResolveTermParents(): void
    {
        if (empty($this->wxrPendingTermParents)) {
            return;
        }

        $this->io->text('Resolving category parent-child relationships...');

        $resolved = 0;
        foreach ($this->wxrPendingTermParents as $link) {
            if ($this->dryRun) {
                break;
            }

            $parentId = $this->wxrFindParentTermId($link['table'], $link['parent_ref']);
            if ($parentId === null || $parentId === $link['child_id']) {
                continue;
            }

            if ($this->wxrWouldCycle($link['table'], $link['child_id'], $parentId)) {
                $this->io->warning(sprintf(
                    'Skipped parent link for term #%d: parent "%s" would create a cycle.',
                    $link['child_id'],
                    $link['parent_ref']
                ));
                continue;
            }

            $this->valeDb->update(
                $link['table'],
                ['parent_id' => $parentId],
                ['id'        => $link['child_id']]
            );
            $resolved++;
        }

        if ($resolved > 0) {
            $this->io->text(sprintf('Resolved %d category parent relationship(s).', $resolved));
        }
    }

    /**
     * Find the Vale id of a WXR parent reference — numeric references are WP
     * term IDs, anything else is a slug (the form WordPress exports).
     */
    private function wxrFindParentTermId(string $table, string $parentRef): ?int
    {
        if (ctype_digit($parentRef)) {
            $id = $this->wxrTermIds[(int) $parentRef] ?? null;
            if ($id !== null && $id > 0) {
                return $id;
            }
        }

        $id = $this->wxrTermSlugs[$table][$parentRef] ?? null;
        if ($id !== null) {
            return $id;
        }

        $id = $this->valeDb->fetchOne(
            'SELECT id FROM ' . $table . ' WHERE slug = ?',
            [$parentRef]
        );

        return ($id !== false && $id !== null) ? (int) $id : null;
    }

    /**
     * True when reparenting $childId under $parentId would create a cycle,
     * i.e. $childId is already an ancestor of $parentId.
     */
    private function wxrWouldCycle(string $table, int $childId, int $parentId): bool
    {
        $current = $parentId;
        $guard   = 0;

        while ($current > 0 && $guard < 64) {
            if ($current === $childId) {
                return true;
            }
            $parent  = $this->valeDb->fetchOne(
                'SELECT parent_id FROM ' . $table . ' WHERE id = ?',
                [$current]
            );
            $current = ($parent !== false && $parent !== null) ? (int) $parent : 0;
            $guard++;
        }

        return false;
    }

    private function wxrImportSingleTerm(SimpleXMLElement $el, string $taxonomy, ProgressBar $progressBar): void
    {
        try {
            $wp      = $el->children(self::NS_WP);
            $name    = $this->cleanImportedText((string) ($wp->term_name ?? $wp->tag_name ?? $wp->cat_name ?? $el->title));
            $rawSlug = (string) ($wp->term_slug ?? $wp->tag_slug ?? $wp->category_nicename ?? '');
            $slug    = (string) Slug::fromString($rawSlug !== '' ? $rawSlug : $name);
            $termId  = (int) ($wp->term_id ?? 0);
            $wpSlug  = $rawSlug;
            // WXR exports the parent as a slug (wp:category_parent / wp:term_parent);
            // some exporters emit the numeric term ID instead.
            $parentRef = trim((string) ($wp->category_parent ?? $wp->term_parent ?? ''));

            if ($taxonomy === 'post_translations' || $taxonomy === 'term_translations') {
                $desc       = (string) $wp->term_description;
                $tname      = (string) $wp->term_name;
                $tslug      = (string) $wp->term_slug;
                $serialized = $this->extractSerializedArray($desc, $tname, $tslug);
                if ($serialized) {
                    $this->wxrTranslations[] = [
                        'taxonomy' => $taxonomy,
                        'data'     => $serialized,
                    ];
                }
                $progressBar->advance();
                return;
            }

            $table = match ($taxonomy) {
                'product_cat' => VL_DB_PREFIX . 'product_categories',
                'product_tag' => VL_DB_PREFIX . 'tags',
                'post_tag'    => VL_DB_PREFIX . 'tags',
                'category'    => VL_DB_PREFIX . 'categories',
                default       => null,
            };

            if ($table === null) {
                return;
            }

            // Apply module filtering
            if ($this->importPostsOnly && ($taxonomy === 'product_cat' || $taxonomy === 'product_tag')) {
                $progressBar->advance();
                return;
            }
            if ($this->importWooOnly && ($taxonomy === 'category' || $taxonomy === 'post_tag')) {
                $progressBar->advance();
                return;
            }
            if ($this->importUsersOnly) {
                $progressBar->advance();
                return;
            }

            $existing = null;
            if ($table === VL_DB_PREFIX . 'tags') {
                $existing = $this->valeDb->fetchAssociative(
                    "SELECT id FROM {$table} WHERE slug = ? AND language = ?",
                    [$slug, 'en']
                );
            } else {
                $existing = $this->valeDb->fetchAssociative(
                    "SELECT id FROM {$table} WHERE slug = ?",
                    [$slug]
                );
            }

            $valeId = 0;
            if ($existing) {
                $valeId                  = (int) $existing['id'];
                $this->termMap[$termId]  = $valeId;
            } else {
                if (!$this->dryRun) {
                    $data = [
                        'name'       => $name,
                        'slug'       => $slug,
                        'language'   => 'en',
                        'created_at' => date('Y-m-d H:i:s'),
                    ];
                    if ($table !== VL_DB_PREFIX . 'product_categories' && $table !== VL_DB_PREFIX . 'tags') {
                        $data['content_type'] = 'post';
                    }
                    if ($table !== VL_DB_PREFIX . 'tags') {
                        $termDesc = trim((string) ($wp->term_description ?? $wp->category_description ?? ''));
                        if ($termDesc !== '') {
                            $data['description'] = $termDesc;
                        }
                    }
                    $this->valeDb->insert($table, $data);
                    $valeId = (int) $this->valeDb->lastInsertId();
                }
                $this->termMap[$termId] = 0;
            }

            if ($valeId > 0) {
                $this->wxrTermIds[$termId] = $valeId;
                $refKeys                   = array_unique(array_filter([$wpSlug, $slug], static fn (string $s): bool => $s !== ''));
                foreach ($refKeys as $refKey) {
                    $this->wxrTermSlugs[$table][$refKey] = $valeId;
                }

                // Deferred: parent terms can appear after their children in the XML.
                if ($parentRef !== '' && $table !== VL_DB_PREFIX . 'tags') {
                    $this->wxrPendingTermParents[] = [
                        'table'      => $table,
                        'child_id'   => $valeId,
                        'parent_ref' => $parentRef,
                    ];
                }
            }
        } catch (\Exception $e) {
            $this->io->warning("Failed to import term: {$name} — " . $e->getMessage());
        }
        $progressBar->advance();
    }

    private function wxrImportMedia(array $mediaItems): void
    {
        $count = count($mediaItems);
        if ($count === 0) {
            return;
        }

        $this->io->section('Importing Media');
        $this->io->text(sprintf('Found %d attachment(s).', $count));
        $progressBar = new ProgressBar($this->io, $count);

        $uploadsDir = base_path('storage/uploads');

        foreach ($mediaItems as $item) {
            try {
                $wp             = $item->children(self::NS_WP);
                $postId         = (int) $wp->post_id;
                $title          = $this->cleanImportedText((string) $item->title);
                $attachmentUrl  = (string) $wp->attachment_url;
                $postDate       = (string) $wp->post_date;
                $metaMap        = $this->wxrParseMeta($wp->postmeta);
                $contentKids    = $item->children(self::NS_CONTENT);
                $excerptKids    = $item->children(self::NS_EXCERPT);

                // Download first so the final local path is known: dedupe must be
                // path-based so equal basenames in different WP folders are not
                // conflated, and the mapping below keeps the real media id.
                $localPath = null;
                if (!$this->dryRun && $this->downloadMedia && $attachmentUrl !== '') {
                    $localPath = $this->wxrDownloadAttachment($attachmentUrl, $uploadsDir);
                }

                if ($localPath !== null) {
                    $existing = $this->valeDb->fetchAssociative(
                        'SELECT id FROM ' . VL_DB_PREFIX . 'media WHERE path = ?',
                        [$localPath]
                    );
                } else {
                    // Dry run or no download: nothing is written locally, so only
                    // resolve already-registered rows via the legacy basename lookup.
                    $existing = $title !== ''
                        ? $this->valeDb->fetchAssociative(
                            'SELECT id FROM ' . VL_DB_PREFIX . 'media WHERE original_name = ?',
                            [$title]
                        )
                        : false;
                }

                if ($existing) {
                    $this->mediaMap[$postId] = (int) $existing['id'];
                    if ($localPath !== null && $attachmentUrl !== '') {
                        $this->importedMediaUrls[] = [
                            'url'  => $attachmentUrl,
                            'path' => $localPath,
                        ];
                    }
                    $progressBar->advance();
                    continue;
                }

                if ($localPath === null) {
                    $this->mediaMap[$postId] = 0;
                    $progressBar->advance();
                    continue;
                }

                $fullPath = $uploadsDir . '/' . $localPath;
                $fileSize = is_file($fullPath) ? filesize($fullPath) : 0;
                $mimeType = mime_content_type($fullPath) ?: null;

                // Validate + sanitize the downloaded file against the site policy.
                if ($this->scanner !== null) {
                    try {
                        $mimeType = $this->scanner->sanitizeFile($fullPath);
                    } catch (\Throwable $e) {
                        $this->io->warning("Skipped attachment {$title}: " . $e->getMessage());
                        $this->mediaMap[$postId] = 0;
                        $progressBar->advance();
                        continue;
                    }
                }

                $this->valeDb->insert(VL_DB_PREFIX . 'media', [
                    'user_id'       => 1,
                    'original_name' => $title,
                    'filename'      => basename($localPath),
                    'path'          => $localPath,
                    'size'          => $fileSize,
                    'mime_type'     => $mimeType,
                    'title'         => $title,
                    'alt_text'      => $this->cleanImportedText((string) ($metaMap['_wp_attachment_image_alt']   ?? '')),
                    'description'   => $this->resolveMediaDescription(
                        (string) $contentKids->encoded,
                        $metaMap,
                        (string) $excerptKids->encoded
                    ),
                    'disk'          => 'local',
                    'created_at'    => $postDate
                        ? date('Y-m-d H:i:s', strtotime($postDate))
                        : date('Y-m-d H:i:s'),
                ]);

                $this->mediaMap[$postId] = (int) $this->valeDb->lastInsertId();
                if ($attachmentUrl !== '') {
                    $this->importedMediaUrls[] = [
                        'url'  => $attachmentUrl,
                        'path' => $localPath,
                    ];
                }
            } catch (\Exception $e) {
                $this->io->warning("Failed to import attachment: {$title} — " . $e->getMessage());
            }
            $progressBar->advance();
        }
        $progressBar->finish();
        $this->io->newLine(2);
    }

    private function wxrImportContentItems(array $items): void
    {
        $count = count($items);
        if ($count === 0) {
            $this->io->note('No content items found.');
            return;
        }

        $this->io->section('Importing Posts, Pages and Products');
        $this->io->text(sprintf('Found %d content item(s).', $count));
        $progressBar = new ProgressBar($this->io, $count);

        $variationItems = [];
        foreach ($items as $item) {
            $wp       = $item->children(self::NS_WP);
            $postType = (string) $wp->post_type;
            if ($postType === 'product_variation') {
                $variationItems[] = $item;
            }
        }

        foreach ($items as $item) {
            try {
                $wp      = $item->children(self::NS_WP);
                $content = $item->children(self::NS_CONTENT);
                $dc      = $item->children(self::NS_DC);
                $excerpt = $item->children(self::NS_EXCERPT);

                $postId        = (int) $wp->post_id;
                $postType      = (string) $wp->post_type;
                $title         = $this->cleanImportedText((string) $item->title ?: '(no title)');
                $body          = ContentMarkerNormalizer::normalize((string) $content->encoded);
                $excerptStr    = (string) $excerpt->encoded;
                $status        = (string) $wp->status;
                $postName      = (string) $wp->post_name;
                $postDate      = (string) $wp->post_date;
                $postModified  = (string) $wp->post_modified;
                $commentStatus = (string) $wp->comment_status;
                $authorLogin   = (string) $dc->creator;
                $parentId      = (int) $wp->post_parent;

                $slug = (string) Slug::fromString($postName ?: $title ?: 'post-' . $postId);

                $isWooType = ($postType === 'product' || $postType === 'shop_order' || $postType === 'product_variation');

                if ($postType === 'product_variation') {
                    $progressBar->advance();
                    continue;
                }
                if ($postType === 'nav_menu_item') {
                    // Handled by importMenus(): never a post.
                    $progressBar->advance();
                    continue;
                }
                if ($this->importPostsOnly && $isWooType) {
                    $progressBar->advance();
                    continue;
                }
                if ($this->importUsersOnly) {
                    $progressBar->advance();
                    continue;
                }
                if ($this->importWooOnly && !$isWooType) {
                    $progressBar->advance();
                    continue;
                }

                $table = match ($postType) {
                    'product'    => VL_DB_PREFIX . 'products',
                    'shop_order' => null,
                    default      => VL_DB_PREFIX . 'posts',
                };

                if ($postType === 'shop_order') {
                    $this->wxrImportOrder($item, $postId);
                    $progressBar->advance();
                    continue;
                }

                $metaMap = $this->wxrParseMeta($wp->postmeta);

                $isVirtual      = ($metaMap['_virtual'] ?? 'no')      === 'yes';
                $isDownloadable = ($metaMap['_downloadable'] ?? 'no') === 'yes';
                $isDigital      = $isVirtual || $isDownloadable;

                $contentType = match ($postType) {
                    'page'    => 'page',
                    'product' => 'post',
                    default   => $postType,
                };

                $authorId = $this->userMap[$authorLogin] ?? null;
                if (!$authorId) {
                    $authorId = $this->valeDb->fetchOne('SELECT id FROM ' . VL_DB_PREFIX . 'users LIMIT 1') ?: 1;
                }

                $lang = $this->wxrGetItemLanguage($item);

                $isExisting = false;
                if ($table === VL_DB_PREFIX . 'products') {
                    $existingRow = $this->valeDb->fetchAssociative(
                        "SELECT id FROM {$table} WHERE slug = ? AND language = ?",
                        [$slug, $lang]
                    );
                    $isExisting = $existingRow !== false;
                } else {
                    $existingRow = $this->valeDb->fetchAssociative(
                        "SELECT id FROM {$table} WHERE slug = ? AND content_type = ? AND language = ?",
                        [$slug, $contentType, $lang]
                    );
                    $isExisting = $existingRow !== false;
                }

                if ($isExisting) {
                    $newRowId               = (int) $existingRow['id'];
                    $this->postMap[$postId] = $newRowId;
                    if ($postType === 'product') {
                        $this->wooProductMap[$postId] = $newRowId;
                    }
                    $this->wxrImportPostSeoMeta($newRowId, $metaMap);
                    if (!$this->dryRun && $newRowId > 0 && isset($item->children(self::NS_WP)->comment)) {
                        $this->wxrImportComments($newRowId, $postType === 'product' ? 'product' : 'post', $item->children(self::NS_WP)->comment);
                    }
                    $progressBar->advance();
                    continue;
                }

                if ($postType === 'page') {
                    $skipReason = $this->pageImportSkipReason($slug, $title);
                    if ($skipReason !== null) {
                        $this->skippedPages[] = ['title' => $title, 'slug' => $slug, 'reason' => $skipReason];
                        $progressBar->advance();
                        continue;
                    }
                }

                if (!$this->dryRun) {
                    if ($table === VL_DB_PREFIX . 'products') {
                        $this->valeDb->insert($table, [
                            'name'                 => $title,
                            'slug'                 => $slug,
                            'description'          => $body,
                            'short_description'    => $excerptStr,
                            'price'                => (float) ($metaMap['_regular_price'] ?? 0),
                            'sale_price'           => isset($metaMap['_sale_price']) && $metaMap['_sale_price'] !== '' ? (float) $metaMap['_sale_price'] : null,
                            'sku'                  => $metaMap['_sku'] ?? null,
                            'stock_quantity'       => (int) ($metaMap['_stock'] ?? 0),
                            'stock_status'         => ($metaMap['_stock_status'] ?? 'instock') === 'instock' ? 'in_stock' : 'out_of_stock',
                            'status'               => $status                                  === 'publish' ? 'published' : 'draft',
                            'published_at'         => $postDate ? date('Y-m-d H:i:s', strtotime($postDate)) : null,
                            'created_at'           => $postDate ? date('Y-m-d H:i:s', strtotime($postDate)) : date('Y-m-d H:i:s'),
                            'updated_at'           => $postModified ? date('Y-m-d H:i:s', strtotime($postModified)) : date('Y-m-d H:i:s'),
                            'language'             => $lang,
                            'type'                 => 'simple',
                            'shipping_class'       => $isDigital ? 'digital' : 'use_global',
                            'download_source'      => 'none',
                            'download_url'         => null,
                            'download_limit'       => 0,
                            'download_expiry_days' => 0,
                            'comment_status'       => $commentStatus === 'open' ? 'enabled' : 'disabled',
                        ]);
                    } else {
                        $this->valeDb->insert($table, [
                            'content_type'     => $contentType,
                            'author_id'        => $authorId,
                            'title'            => $title,
                            'slug'             => $slug,
                            'excerpt'          => $excerptStr,
                            'body'             => ButtonBlockConverter::convertWordPress($body),
                            'status'           => $status === 'publish' ? 'published' : 'draft',
                            'published_at'     => $postDate ? date('Y-m-d H:i:s', strtotime($postDate)) : null,
                            'created_at'       => $postDate ? date('Y-m-d H:i:s', strtotime($postDate)) : date('Y-m-d H:i:s'),
                            'updated_at'       => $postModified ? date('Y-m-d H:i:s', strtotime($postModified)) : date('Y-m-d H:i:s'),
                            'language'         => $lang,
                            'comment_status'   => $commentStatus === 'open' ? 'enabled' : 'disabled',
                        ]);
                    }

                    $newRowId = (int) $this->valeDb->lastInsertId();
                } else {
                    $newRowId = 0;
                }

                $this->postMap[$postId] = $newRowId;

                if ($postType === 'product' && $newRowId > 0) {
                    $this->wooProductMap[$postId] = $newRowId;
                }

                if (!$this->dryRun && $newRowId > 0 && isset($metaMap['_thumbnail_id'])) {
                    $mediaId = $this->mediaMap[(int) $metaMap['_thumbnail_id']] ?? null;
                    if ($mediaId) {
                        $this->valeDb->update(
                            $table,
                            ['featured_image_id' => $mediaId],
                            ['id'                => $newRowId]
                        );
                    }
                }

                if (!$this->dryRun && $newRowId > 0) {
                    $this->wxrImportPostSeoMeta($newRowId, $metaMap);
                    if ($table === VL_DB_PREFIX . 'products' && $isDigital) {
                        $this->applyWooProductDownloads($newRowId, $title, $metaMap);
                    }
                    if (isset($item->children(self::NS_WP)->comment)) {
                        $this->wxrImportComments($newRowId, $postType === 'product' ? 'product' : 'post', $item->children(self::NS_WP)->comment);
                    }
                    $this->wxrImportTermRelationships($item, $newRowId, $postType);
                }
            } catch (\Exception $e) {
                $title = isset($item) ? (string) $item->title : 'unknown';
                $this->io->warning("Failed to import item: {$title} — " . $e->getMessage());
            }
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->io->newLine(2);
        $this->printSkippedPagesReport();

        if ($variationItems !== [] && ($this->importAll || $this->importWooOnly)) {
            $this->wxrImportVariations($variationItems);
        }

        if (!$this->importPostsOnly && !$this->importUsersOnly && !$this->dryRun) {
            $this->wxrLinkRelatedProducts($items);
        }

        if ($this->dryRun) {
            foreach ($items as $item) {
                $wp     = $item->children(self::NS_WP);
                $postId = (int) $wp->post_id;
                if (!isset($this->postMap[$postId])) {
                    continue;
                }
                $slug        = (string) Slug::fromString((string) $wp->post_name ?: (string) $item->title ?: 'post-' . $postId);
                $postType    = (string) $wp->post_type;
                $contentType = $postType === 'page' ? 'page' : ($postType === 'product' ? 'post' : $postType);
                $table       = $postType === 'product' ? VL_DB_PREFIX . 'products' : VL_DB_PREFIX . 'posts';
                $lang        = $this->wxrGetItemLanguage($item);
                if ($table === VL_DB_PREFIX . 'products') {
                    $existing = $this->valeDb->fetchAssociative(
                        "SELECT id FROM {$table} WHERE slug = ? AND language = ?",
                        [$slug, $lang]
                    );
                } else {
                    $existing = $this->valeDb->fetchAssociative(
                        "SELECT id FROM {$table} WHERE slug = ? AND content_type = ? AND language = ?",
                        [$slug, $contentType, $lang]
                    );
                }
                $this->postMap[$postId] = $existing ? (int) $existing['id'] : 0;
            }
        }
    }

    private function wxrImportVariations(array $items): void
    {
        $this->io->section('Importing WooCommerce Variations');
        $this->io->text(sprintf('Found %d variation(s).', count($items)));
        $progressBar                 = new ProgressBar($this->io, count($items));
        $parentPriceMin              = [];
        $parentVariationCount        = [];
        $parentDigitalVariationCount = [];

        foreach ($items as $item) {
            try {
                $wp           = $item->children(self::NS_WP);
                $postId       = (int) $wp->post_id;
                $parentPostId = (int) $wp->post_parent;
                $metaMap      = $this->wxrParseMeta($wp->postmeta);

                $parentProductId = $this->postMap[$parentPostId] ?? null;
                if (!$parentProductId) {
                    $this->io->warning("Skipped variation #{$postId}: parent product #{$parentPostId} was not imported.");
                    $progressBar->advance();
                    continue;
                }

                if ($this->dryRun) {
                    $this->postMap[$postId] = 0;
                    $progressBar->advance();
                    continue;
                }

                $this->valeDb->update(VL_DB_PREFIX . 'products', ['type' => 'variable'], ['id' => $parentProductId]);

                $attributes = [];
                foreach ($metaMap as $key => $value) {
                    if (str_starts_with($key, 'attribute_')) {
                        $attributes[str_replace('attribute_', '', $key)] = $value;
                    }
                }

                $isDownloadable = ($metaMap['_downloadable'] ?? 'no') === 'yes';
                $isVirtual      = ($metaMap['_virtual'] ?? 'no')      === 'yes';

                $variationPrice = (float) ($metaMap['_regular_price'] ?? 0);
                if ($variationPrice > 0.0) {
                    $parentPriceMin[$parentProductId] = min($parentPriceMin[$parentProductId] ?? $variationPrice, $variationPrice);
                }

                $thumbId = $metaMap['_thumbnail_id'] ?? null;

                $this->valeDb->insert(VL_DB_PREFIX . 'product_variations', [
                    'product_id'       => $parentProductId,
                    'attributes'       => json_encode($attributes),
                    'sku'              => $metaMap['_sku'] ?? null,
                    'price'            => $variationPrice,
                    'sale_price'       => (isset($metaMap['_sale_price']) && $metaMap['_sale_price'] !== '') ? (float) $metaMap['_sale_price'] : null,
                    'stock_quantity'   => (int) ($metaMap['_stock'] ?? 0),
                    'stock_status'     => ($metaMap['_stock_status'] ?? 'instock') === 'instock' ? 'in_stock' : 'out_of_stock',
                    'weight'           => isset($metaMap['_weight']) ? (float) $metaMap['_weight'] : null,
                    'image_id'         => $thumbId !== null && isset($this->mediaMap[$thumbId]) ? $this->mediaMap[$thumbId] : null,
                    'shipping_class'   => ($isVirtual || $isDownloadable) ? 'digital' : 'use_parent',
                    'download_mode'    => 'use_parent',
                    'download_url'     => null,
                ]);

                $newVariationId         = (int) $this->valeDb->lastInsertId();
                $this->postMap[$postId] = $newVariationId;

                $parentVariationCount[$parentProductId] = ($parentVariationCount[$parentProductId] ?? 0) + 1;
                if ($isVirtual || $isDownloadable) {
                    $parentDigitalVariationCount[$parentProductId] = ($parentDigitalVariationCount[$parentProductId] ?? 0) + 1;
                }

                if ($isDownloadable) {
                    $this->applyWooVariationDownloads($newVariationId, $postId, $metaMap);
                }
            } catch (\Exception $e) {
                $title = isset($item) ? (string) $item->title : 'unknown';
                $this->io->warning("Failed to import variation: {$title} — " . $e->getMessage());
            }
            $progressBar->advance();
        }

        foreach ($parentPriceMin as $productId => $minPrice) {
            $productId = (int) $productId;
            $current   = (float) $this->valeDb->fetchOne('SELECT price FROM ' . VL_DB_PREFIX . 'products WHERE id = ?', [$productId]) ?: 0.0;
            if ($current <= 0.0) {
                $this->valeDb->update(VL_DB_PREFIX . 'products', ['price' => $minPrice], ['id' => $productId]);
            }
        }

        // WooCommerce keeps Virtual/Downloadable on the variation rows for
        // variable products, so the parent imports as physical. When every
        // variation is digital the parent must be digital too.
        foreach ($parentVariationCount as $productId => $count) {
            $productId = (int) $productId;
            if ($count > 0 && ($parentDigitalVariationCount[$productId] ?? 0) === $count) {
                $this->valeDb->update(VL_DB_PREFIX . 'products', ['shipping_class' => 'digital'], ['id' => $productId]);
            }
        }

        $progressBar->finish();
        $this->io->newLine(2);
    }

    /**
     * Link WooCommerce upsell/cross-sell relationships (WXR import mode).
     *
     * Runs as a second pass over the content items so all referenced products
     * are already mapped to their Vale IDs.
     *
     * @param array<SimpleXMLElement> $items
     */
    private function wxrLinkRelatedProducts(array $items): void
    {
        $updates = [];
        $map     = $this->wooProductMap;

        foreach ($items as $item) {
            $wp       = $item->children(self::NS_WP);
            $postId   = (int) $wp->post_id;
            $valeId   = $map[$postId] ?? null;
            if (!$valeId) {
                continue;
            }

            $metaMap = $this->wxrParseMeta($wp->postmeta);

            $columns = [];
            foreach (['_upsell_ids' => 'upsell_ids', '_crosssell_ids' => 'cross_sell_ids'] as $metaKey => $column) {
                $resolved = $this->resolveWooRelatedIds($this->parseWooRelatedIdList((string) ($metaMap[$metaKey] ?? '')));
                if ($resolved !== []) {
                    $columns[$column] = json_encode($resolved);
                }
            }

            if ($columns !== []) {
                $updates[$valeId] = $columns;
            }
        }

        if ($updates === []) {
            return;
        }

        foreach ($updates as $productId => $columns) {
            $this->valeDb->update(VL_DB_PREFIX . 'products', $columns, ['id' => (int) $productId]);
        }

        $this->io->text(sprintf('Linked upsell/cross-sell relationships on %d product(s).', count($updates)));
    }

    private function wxrParseMeta(SimpleXMLElement $postmeta): array
    {
        $meta = [];
        foreach ($postmeta as $m) {
            $key   = (string) $m->children(self::NS_WP)->meta_key;
            $value = (string) $m->children(self::NS_WP)->meta_value;
            if ($key !== '') {
                $meta[$key] = $value;
            }
        }
        return $meta;
    }

    private function wxrImportPostSeoMeta(int $valePostId, array $metaMap): void
    {
        $seoFields   = [];
        $seoMetaData = [
            'entity_type' => 'post',
            'entity_id'   => $valePostId,
            'created_at'  => date('Y-m-d H:i:s'),
        ];

        if (isset($metaMap['_yoast_wpseo_title'])) {
            $seoFields['meta_title']   = $metaMap['_yoast_wpseo_title'];
            $seoMetaData['meta_title'] = $metaMap['_yoast_wpseo_title'];
        }
        if (isset($metaMap['_yoast_wpseo_metadesc'])) {
            $seoFields['meta_description']   = $metaMap['_yoast_wpseo_metadesc'];
            $seoMetaData['meta_description'] = $metaMap['_yoast_wpseo_metadesc'];
        }
        if (isset($metaMap['rank_math_title'])) {
            $seoFields['meta_title']   = $metaMap['rank_math_title'];
            $seoMetaData['meta_title'] = $metaMap['rank_math_title'];
        }
        if (isset($metaMap['rank_math_description'])) {
            $seoFields['meta_description']   = $metaMap['rank_math_description'];
            $seoMetaData['meta_description'] = $metaMap['rank_math_description'];
        }

        // OG
        if (isset($metaMap['_yoast_wpseo_opengraph-title']) || isset($metaMap['rank_math_facebook_title'])) {
            $seoMetaData['og_title'] = $metaMap['_yoast_wpseo_opengraph-title'] ?? $metaMap['rank_math_facebook_title'];
        }
        if (isset($metaMap['_yoast_wpseo_opengraph-description']) || isset($metaMap['rank_math_facebook_description'])) {
            $seoMetaData['og_description'] = $metaMap['_yoast_wpseo_opengraph-description'] ?? $metaMap['rank_math_facebook_description'];
        }
        if (isset($metaMap['_yoast_wpseo_opengraph-image']) || isset($metaMap['rank_math_facebook_image'])) {
            $imageId                    = (int) ($metaMap['_yoast_wpseo_opengraph-image'] ?? $metaMap['rank_math_facebook_image']);
            $seoMetaData['og_image_id'] = isset($this->mediaMap[$imageId]) ? $this->mediaMap[$imageId] : null;
        }

        // Twitter
        if (isset($metaMap['_yoast_wpseo_twitter-title']) || isset($metaMap['rank_math_twitter_title'])) {
            $seoMetaData['twitter_card_type'] = 'summary_large_image';
        } elseif (isset($seoMetaData['og_image_id'])) {
            $seoMetaData['twitter_card_type'] = 'summary_large_image';
        }

        // Canonical, robots, focus keyword
        if (isset($metaMap['_yoast_wpseo_canonical']) || isset($metaMap['rank_math_canonical_url'])) {
            $seoMetaData['canonical_url'] = $metaMap['_yoast_wpseo_canonical'] ?? $metaMap['rank_math_canonical_url'];
        }
        if (isset($metaMap['_yoast_wpseo_meta-robots']) || isset($metaMap['rank_math_robots'])) {
            $seoMetaData['robots'] = $metaMap['_yoast_wpseo_meta-robots'] ?? $metaMap['rank_math_robots'];
        }
        if (isset($metaMap['_yoast_wpseo_focuskw']) || isset($metaMap['rank_math_focus_keyword'])) {
            $seoMetaData['focus_keyword'] = $metaMap['_yoast_wpseo_focuskw'] ?? $metaMap['rank_math_focus_keyword'];
        }

        if ($seoFields !== []) {
            try {
                $this->valeDb->update(VL_DB_PREFIX . 'posts', $seoFields, ['id' => $valePostId]);
            } catch (\Exception) {
                // Skip
            }
        }

        if (count($seoMetaData) > 3) {
            try {
                $this->valeDb->insert(VL_DB_PREFIX . 'seo_meta', $seoMetaData);
            } catch (\Exception) {
                // Skip
            }
        }
    }

    private function wxrDownloadAttachment(string $url, string $uploadsDir): ?string
    {
        if (str_starts_with($url, '/')) {
            $baseUrl = rtrim(env('APP_URL', 'http://localhost'), '/');
            $url     = $baseUrl . $url;
        }

        $path         = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        $relativePath = ltrim($path, '/');

        if (preg_match('#^(?:wp-content/)?uploads/(.+)$#', $relativePath, $m)) {
            $relativePath = $m[1];
        } else {
            $relativePath = basename($path);
        }

        $relativePath = $this->sanitizeUploadRelativePath($relativePath, $url);
        if ($relativePath === null) {
            return null;
        }

        $destPath = $uploadsDir . '/' . $relativePath;
        if (is_file($destPath)) {
            return $relativePath;
        }

        return $this->downloadIntoUploads($url, $destPath, 'Vale WXR Importer/1.0')
            ? $relativePath
            : null;
    }

    private function importComments(): void
    {
        $this->io->section('Importing Comments');

        $wpComments = $this->wpDb->fetchAllAssociative("
            SELECT c.* FROM {$this->wpPrefix}comments c
            WHERE c.comment_type = 'comment'
              AND c.comment_post_ID IN (
                  SELECT ID FROM {$this->wpPrefix}posts WHERE post_type IN ('post', 'page', 'product')
              )
        ");

        if (empty($wpComments)) {
            $this->io->note('No comments found.');
            return;
        }

        $progressBar = new ProgressBar($this->io, count($wpComments));

        foreach ($wpComments as $wpComment) {
            $wpPostId   = (int) $wpComment['comment_post_ID'];
            $valePostId = $this->postMap[$wpPostId] ?? null;

            if (!$valePostId) {
                $progressBar->advance();
                continue;
            }

            $wpPost = $this->wpDb->fetchAssociative(
                "SELECT post_type FROM {$this->wpPrefix}posts WHERE ID = ?",
                [$wpPostId]
            );
            $postType   = $wpPost['post_type'] ?? 'post';
            $entityType = $postType === 'product' ? 'product' : 'post';

            $authorName  = (string) $wpComment['comment_author'];
            $authorEmail = (string) $wpComment['comment_author_email'];
            $commentDate = (string) $wpComment['comment_date'];

            $existing = $this->valeDb->fetchAssociative(
                'SELECT id FROM ' . VL_DB_PREFIX . 'comments WHERE entity_id = ? AND entity_type = ? AND author_email = ? AND created_at = ?',
                [$valePostId, $entityType, $authorEmail, $commentDate]
            );

            if ($existing) {
                $this->commentMap[$wpComment['comment_ID']] = (int) $existing['id'];
                $progressBar->advance();
                continue;
            }

            $userId = null;
            if (!empty($wpComment['user_id']) && $wpComment['user_id'] > 0) {
                $userId = $this->userMap[(int) $wpComment['user_id']] ?? null;
            }

            $approved = (int) $wpComment['comment_approved'];
            $status   = match ($approved) {
                1       => 'approved',
                default => 'pending',
            };

            try {
                $this->valeDb->insert(VL_DB_PREFIX . 'comments', [
                    'entity_type'    => $entityType,
                    'entity_id'      => $valePostId,
                    'parent_id'      => $wpComment['comment_parent'] > 0 ? ($this->commentMap[(int) $wpComment['comment_parent']] ?? null) : null,
                    'author_name'    => $authorName,
                    'author_email'   => $authorEmail,
                    'author_url'     => $wpComment['comment_author_url'] ?: null,
                    'author_ip'      => $wpComment['comment_author_IP'] ?: null,
                    'user_id'        => $userId,
                    'body'           => $wpComment['comment_content'],
                    'status'         => $status,
                    'verified'       => 0,
                    'notify_replies' => 0,
                    'locale'         => 'en',
                    'created_at'     => $commentDate,
                ]);
                $this->commentMap[$wpComment['comment_ID']] = (int) $this->valeDb->lastInsertId();
            } catch (\Exception $e) {
                $this->io->warning("\nFailed to import comment: {$authorName}\nError: " . $e->getMessage());
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->io->newLine(2);
    }

    private function importOrders(): void
    {
        $this->io->section('Importing WooCommerce Orders');

        $wcOrdersTable     = $this->wpPrefix . 'wc_orders';
        $legacyOrdersTable = $this->wpPrefix . 'posts';

        $hasWcOrders     = $this->tableExists($this->wpDb, $wcOrdersTable);
        $hasLegacyOrders = false;

        if (!$hasWcOrders) {
            $hasLegacyOrders = $this->wpDb->fetchOne(
                "SELECT COUNT(*) FROM {$this->wpPrefix}posts WHERE post_type = 'shop_order'"
            ) > 0;
        }

        if (!$hasWcOrders && !$hasLegacyOrders) {
            $this->io->note('No WooCommerce orders found.');
            return;
        }

        if ($hasWcOrders) {
            $this->importWcOrders($wcOrdersTable);
        } elseif ($hasLegacyOrders) {
            $this->importLegacyWooOrders($legacyOrdersTable);
        }
    }

    private function importWcOrders(string $wcOrdersTable): void
    {
        $wpOrders = $this->wpDb->fetchAllAssociative("SELECT * FROM {$wcOrdersTable}");

        if (empty($wpOrders)) {
            $this->io->note('No WooCommerce orders found.');
            return;
        }

        $progressBar = new ProgressBar($this->io, count($wpOrders));

        foreach ($wpOrders as $wpOrder) {
            $orderId     = (int) $wpOrder['id'];
            $orderNumber = 'ORD-' . $wpOrder['id'];
            $status      = $this->mapWooStatus((string) $wpOrder['status']);

            $existing = $this->valeDb->fetchAssociative(
                'SELECT id FROM ' . VL_DB_PREFIX . 'orders WHERE order_number = ?',
                [$orderNumber]
            );

            if ($existing) {
                $this->orderMap[$orderId] = (int) $existing['id'];
                $progressBar->advance();
                continue;
            }

            $meta = $this->wpDb->fetchAllAssociative(
                "SELECT meta_key, meta_value FROM {$this->wpPrefix}wc_orders_meta WHERE order_id = ?",
                [$orderId]
            );
            $metaMap = [];
            foreach ($meta as $m) {
                $metaMap[$m['meta_key']] = $m['meta_value'];
            }

            // HPOS stores addresses in wc_order_addresses, not in order meta —
            // backfill the _billing_* / _shipping_* keys the builders expect.
            $metaMap += $this->addressMetaFallback($orderId);

            $userId   = $this->userMap[(int) $wpOrder['customer_id']] ?? null;
            $currency = (string) $wpOrder['currency'];
            $total    = (float) $wpOrder['total_amount'];
            $tax      = (float) $wpOrder['tax_amount'];

            $billing         = $this->buildBillingFromWcMeta($metaMap, (string) $wpOrder['billing_email']);
            $shippingAddress = $this->buildShippingFromWcMeta($metaMap);

            $couponCode = $this->extractWcCouponCodes($orderId, $metaMap);

            $orderDate = !empty($wpOrder['date_created_gmt'])
                ? date('Y-m-d H:i:s', strtotime((string) $wpOrder['date_created_gmt']))
                : date('Y-m-d H:i:s');

            $paidAt = null;
            if (in_array($status, ['processing', 'completed'], true) && !empty($wpOrder['date_created_gmt'])) {
                $paidAt = date('Y-m-d H:i:s', strtotime((string) $wpOrder['date_created_gmt']));
            }

            try {
                $this->valeDb->insert(VL_DB_PREFIX . 'orders', [
                    'order_number'     => $orderNumber,
                    'user_id'          => $userId,
                    'status'           => $status,
                    'locale'           => 'en',
                    'currency'         => $currency ?: 'USD',
                    'subtotal'         => max(0, $total - $tax),
                    'tax'              => $tax,
                    'shipping'         => 0,
                    'discount'         => 0,
                    'total'            => $total,
                    'coupon_code'      => $couponCode,
                    'billing'          => json_encode($billing),
                    'shipping_address' => json_encode($shippingAddress),
                    'customer_note'    => $wpOrder['customer_note'] ?: null,
                    'payment_method'   => $wpOrder['payment_method_title'] ?: $wpOrder['payment_method'],
                    'transaction_id'   => $wpOrder['transaction_id'] ?: null,
                    'payment_token'    => null,
                    'paid_at'          => $paidAt,
                    'carrier'          => null,
                    'tracking_number'  => null,
                    'order_type'       => 'shop',
                    'plan_id'          => null,
                    'subscription_id'  => null,
                    'plan_name'        => null,
                    'created_at'       => $orderDate,
                    'updated_at'       => $orderDate,
                ]);
                $valeOrderId              = (int) $this->valeDb->lastInsertId();
                $this->orderMap[$orderId] = $valeOrderId;

                $this->importOrderItems($orderId, $valeOrderId);
            } catch (\Exception $e) {
                $this->io->warning("\nFailed to import order {$orderNumber}: " . $e->getMessage());
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->io->newLine(2);
    }

    private function importLegacyWooOrders(string $legacyPostsTable): void
    {
        $wpOrders = $this->wpDb->fetchAllAssociative("
            SELECT p.* FROM {$legacyPostsTable} p
            WHERE p.post_type = 'shop_order'
        ");

        if (empty($wpOrders)) {
            $this->io->note('No legacy WooCommerce orders found.');
            return;
        }

        $progressBar = new ProgressBar($this->io, count($wpOrders));

        foreach ($wpOrders as $wpOrder) {
            $orderId     = (int) $wpOrder['ID'];
            $orderNumber = 'ORD-' . $orderId;
            $status      = $this->mapLegacyWooStatus((string) $wpOrder['post_status']);

            $existing = $this->valeDb->fetchAssociative(
                'SELECT id FROM ' . VL_DB_PREFIX . 'orders WHERE order_number = ?',
                [$orderNumber]
            );

            if ($existing) {
                $this->orderMap[$orderId] = (int) $existing['id'];
                $progressBar->advance();
                continue;
            }

            $meta = $this->wpDb->fetchAllAssociative(
                "SELECT meta_key, meta_value FROM {$this->wpPrefix}postmeta WHERE post_id = ?",
                [$orderId]
            );
            $metaMap = [];
            foreach ($meta as $m) {
                $metaMap[$m['meta_key']] = $m['meta_value'];
            }

            $userId     = null;
            $customerId = isset($metaMap['_customer_user']) ? (int) $metaMap['_customer_user'] : 0;
            if ($customerId > 0 && isset($this->userMap[$customerId])) {
                $userId = $this->userMap[$customerId];
            }

            $total = isset($metaMap['_order_total']) ? (float) $metaMap['_order_total'] : 0;
            $tax   = isset($metaMap['_order_tax']) ? (float) $metaMap['_order_tax'] : 0;

            $billing         = $this->buildBillingFromLegacyMeta($metaMap);
            $shippingAddress = $this->buildShippingFromLegacyMeta($metaMap);

            $couponCode = $this->extractLegacyWcCouponCodes($orderId, $metaMap);

            $orderDate = !empty($wpOrder['post_date'])
                ? date('Y-m-d H:i:s', strtotime((string) $wpOrder['post_date']))
                : date('Y-m-d H:i:s');

            $paidAt = null;
            if (in_array($status, ['processing', 'completed'], true) && !empty($wpOrder['post_date'])) {
                $paidAt = date('Y-m-d H:i:s', strtotime((string) $wpOrder['post_date']));
            }

            try {
                $this->valeDb->insert(VL_DB_PREFIX . 'orders', [
                    'order_number'     => $orderNumber,
                    'user_id'          => $userId,
                    'status'           => $status,
                    'locale'           => 'en',
                    'currency'         => isset($metaMap['_order_currency']) ? $metaMap['_order_currency'] : 'USD',
                    'subtotal'         => max(0, $total - $tax),
                    'tax'              => $tax,
                    'shipping'         => isset($metaMap['_order_shipping']) ? (float) $metaMap['_order_shipping'] : 0,
                    'discount'         => 0,
                    'total'            => $total,
                    'coupon_code'      => $couponCode,
                    'billing'          => json_encode($billing),
                    'shipping_address' => json_encode($shippingAddress),
                    'customer_note'    => $wpOrder['post_excerpt'] ?: null,
                    'payment_method'   => isset($metaMap['_payment_method']) ? $metaMap['_payment_method'] : null,
                    'transaction_id'   => isset($metaMap['_transaction_id']) ? $metaMap['_transaction_id'] : null,
                    'payment_token'    => null,
                    'paid_at'          => $paidAt,
                    'carrier'          => null,
                    'tracking_number'  => null,
                    'order_type'       => 'shop',
                    'plan_id'          => null,
                    'subscription_id'  => null,
                    'plan_name'        => null,
                    'created_at'       => $orderDate,
                    'updated_at'       => $orderDate,
                ]);
                $valeOrderId              = (int) $this->valeDb->lastInsertId();
                $this->orderMap[$orderId] = $valeOrderId;

                $this->importLegacyOrderItems($orderId, $valeOrderId);
            } catch (\Exception $e) {
                $this->io->warning("\nFailed to import order {$orderNumber}: " . $e->getMessage());
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->io->newLine(2);
    }

    private function importOrderItems(int $oldOrderId, int $valeOrderId): void
    {
        $orderItemsTable    = $this->wpPrefix . 'woocommerce_order_items';
        $orderItemMetaTable = $this->wpPrefix . 'woocommerce_order_itemmeta';

        if (!$this->tableExists($this->wpDb, $orderItemsTable)) {
            return;
        }

        $items = $this->wpDb->fetchAllAssociative("
            SELECT oi.* FROM {$orderItemsTable} oi
            WHERE oi.order_id = ? AND oi.order_item_type = 'line_item'
        ", [$oldOrderId]);

        foreach ($items as $item) {
            $meta = $this->wpDb->fetchAllAssociative(
                "SELECT meta_key, meta_value FROM {$orderItemMetaTable} WHERE order_item_id = ?",
                [$item['order_item_id']]
            );
            $metaMap = [];
            foreach ($meta as $m) {
                $metaMap[$m['meta_key']] = $m['meta_value'];
            }

            $oldProductId   = isset($metaMap['_product_id']) ? (int) $metaMap['_product_id'] : null;
            $oldVariationId = isset($metaMap['_variation_id']) && $metaMap['_variation_id'] > 0 ? (int) $metaMap['_variation_id'] : null;
            $qty            = isset($metaMap['_qty']) ? (int) $metaMap['_qty'] : 1;
            $price          = isset($metaMap['_line_total']) ? (float) $metaMap['_line_total'] : 0;
            $subtotal       = isset($metaMap['_line_subtotal']) ? (float) $metaMap['_line_subtotal'] : $price;

            // Resolve old Woo post IDs to Vale IDs (null when the product was
            // not imported — the admin template then shows a plain name).
            [$productId, $variationId] = $this->resolveOrderItemIds($oldProductId, $oldVariationId);
            $sku                       = null;
            if ($oldVariationId !== null) {
                $sku = $this->wooSkuForPost($oldVariationId);
            }
            if ($sku === null && $oldProductId !== null) {
                $sku = $this->wooSkuForPost($oldProductId);
            }

            $variationData = [];
            if ($variationId !== null) {
                $variationData = ['variation_id' => $variationId];
            }

            try {
                $this->valeDb->insert(VL_DB_PREFIX . 'order_items', [
                    'order_id'       => $valeOrderId,
                    'product_id'     => $productId,
                    'variation_id'   => $variationId,
                    'name'           => $item['order_item_name'],
                    'sku'            => $sku,
                    'quantity'       => $qty,
                    'price'          => $price,
                    'subtotal'       => $subtotal,
                    'total'          => $subtotal,
                    'variation_data' => json_encode($variationData),
                ]);
            } catch (\Exception $e) {
                // Skip item
            }
        }
    }

    private function importLegacyOrderItems(int $oldOrderId, int $valeOrderId): void
    {
        $orderItemsTable    = $this->wpPrefix . 'woocommerce_order_items';
        $orderItemMetaTable = $this->wpPrefix . 'woocommerce_order_itemmeta';

        if (!$this->tableExists($this->wpDb, $orderItemsTable)) {
            return;
        }

        $items = $this->wpDb->fetchAllAssociative("
            SELECT oi.* FROM {$orderItemsTable} oi
            WHERE oi.order_id = ? AND oi.order_item_type = 'line_item'
        ", [$oldOrderId]);

        foreach ($items as $item) {
            $meta = $this->wpDb->fetchAllAssociative(
                "SELECT meta_key, meta_value FROM {$orderItemMetaTable} WHERE order_item_id = ?",
                [$item['order_item_id']]
            );
            $metaMap = [];
            foreach ($meta as $m) {
                $metaMap[$m['meta_key']] = $m['meta_value'];
            }

            $oldProductId   = isset($metaMap['_product_id']) ? (int) $metaMap['_product_id'] : null;
            $oldVariationId = isset($metaMap['_variation_id']) && $metaMap['_variation_id'] > 0 ? (int) $metaMap['_variation_id'] : null;
            $qty            = isset($metaMap['_qty']) ? (int) $metaMap['_qty'] : 1;
            $price          = isset($metaMap['_line_total']) ? (float) $metaMap['_line_total'] : 0;
            $subtotal       = isset($metaMap['_line_subtotal']) ? (float) $metaMap['_line_subtotal'] : $price;

            // Resolve old Woo post IDs to Vale IDs (null when the product was
            // not imported — the admin template then shows a plain name).
            [$productId, $variationId] = $this->resolveOrderItemIds($oldProductId, $oldVariationId);
            $sku                       = null;
            if ($oldVariationId !== null) {
                $sku = $this->wooSkuForPost($oldVariationId);
            }
            if ($sku === null && $oldProductId !== null) {
                $sku = $this->wooSkuForPost($oldProductId);
            }

            $variationData = [];
            if ($variationId !== null) {
                $variationData = ['variation_id' => $variationId];
            }

            try {
                $this->valeDb->insert(VL_DB_PREFIX . 'order_items', [
                    'order_id'       => $valeOrderId,
                    'product_id'     => $productId,
                    'variation_id'   => $variationId,
                    'name'           => $item['order_item_name'],
                    'sku'            => $sku,
                    'quantity'       => $qty,
                    'price'          => $price,
                    'subtotal'       => $subtotal,
                    'total'          => $subtotal,
                    'variation_data' => json_encode($variationData),
                ]);
            } catch (\Exception $e) {
                // Skip item
            }
        }
    }

    private function mapWooStatus(string $wcStatus): string
    {
        return match ($wcStatus) {
            'wc-pending', 'wc-on-hold'     => 'pending',
            'wc-processing'                => 'processing',
            'wc-completed'                 => 'completed',
            'wc-refunded'                  => 'refunded',
            'wc-cancelled', 'wc-failed'    => 'cancelled',
            default                        => 'pending',
        };
    }

    private function mapLegacyWooStatus(string $postStatus): string
    {
        return match ($postStatus) {
            'wc-pending', 'wc-on-hold'     => 'pending',
            'wc-processing'                => 'processing',
            'wc-completed'                 => 'completed',
            'wc-refunded'                  => 'refunded',
            'wc-cancelled', 'wc-failed'    => 'cancelled',
            default                        => 'pending',
        };
    }

    /**
     * Read HPOS order addresses (wc_order_addresses) as _billing_* / _shipping_*
     * meta pairs. Returned keys are merged with += so real order meta wins.
     *
     * @return array<string, string>
     */
    private function addressMetaFallback(int $orderId): array
    {
        $table = $this->wpPrefix . 'wc_order_addresses';
        if (!$this->tableExists($this->wpDb, $table)) {
            return [];
        }
        $rows = $this->wpDb->fetchAllAssociative(
            "SELECT * FROM {$table} WHERE order_id = ?",
            [$orderId]
        );
        $out = [];
        foreach ($rows as $r) {
            $prefix = ($r['address_type'] ?? '') === 'shipping' ? '_shipping_' : '_billing_';
            foreach (['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone'] as $field) {
                if (isset($r[$field]) && trim((string) $r[$field]) !== '') {
                    $out[$prefix . $field] = (string) $r[$field];
                }
            }
        }
        return $out;
    }

    /**
     * Resolve a Vale media description from the competing WordPress texts.
     * Priority: post_content (core Description) → _wp_attachment_description
     * meta (plugin data) → post_excerpt (caption, last resort so no text is
     * lost). Returns null when all are empty.
     *
     * @param array<string, string> $metaMap
     */
    private function resolveMediaDescription(?string $postContent, array $metaMap, ?string $caption): ?string
    {
        $candidates = [
            trim((string) $postContent),
            trim((string) ($metaMap['_wp_attachment_description'] ?? '')),
            trim((string) $caption),
        ];
        foreach ($candidates as $candidate) {
            if ($candidate !== '') {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Map old WooCommerce product/variation post IDs to Vale IDs using the
     * maps built during the product import.
     *
     * @return array{0: ?int, 1: ?int} [valeProductId, valeVariationId]
     */
    private function resolveOrderItemIds(?int $oldProductId, ?int $oldVariationId): array
    {
        $valeProductId = $oldProductId !== null
            ? ($this->wooProductMap[$oldProductId] ?? $this->postMap[$oldProductId] ?? null)
            : null;
        $valeVariationId = $oldVariationId !== null
            ? ($this->postMap[$oldVariationId] ?? null)
            : null;
        return [$valeProductId, $valeVariationId];
    }

    /** Look up the Woo _sku of a product or variation post (for display). */
    private function wooSkuForPost(int $wpPostId): ?string
    {
        $sku = $this->wpDb->fetchOne(
            "SELECT meta_value FROM {$this->wpPrefix}postmeta WHERE post_id = ? AND meta_key = '_sku'",
            [$wpPostId]
        );
        $sku = is_string($sku) ? trim($sku) : '';
        return $sku !== '' ? $sku : null;
    }

    private function buildBillingFromWcMeta(array $metaMap, string $defaultEmail): array
    {
        $firstName = $metaMap['_billing_first_name'] ?? '';
        $lastName  = $metaMap['_billing_last_name']  ?? '';
        $email     = !empty($metaMap['_billing_email']) ? $metaMap['_billing_email'] : $defaultEmail;
        $phone     = $metaMap['_billing_phone']     ?? '';
        $address1  = $metaMap['_billing_address_1'] ?? '';
        $city      = $metaMap['_billing_city']      ?? '';
        $postcode  = $metaMap['_billing_postcode']  ?? '';
        $country   = $metaMap['_billing_country']   ?? '';

        return [
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'email'      => $email,
            'phone'      => $phone,
            'address_1'  => $address1,
            'city'       => $city,
            'postcode'   => $postcode,
            'country'    => $country,
        ];
    }

    private function buildShippingFromWcMeta(array $metaMap): array
    {
        $firstName = $metaMap['_shipping_first_name'] ?? '';
        $lastName  = $metaMap['_shipping_last_name']  ?? '';
        $address1  = $metaMap['_shipping_address_1']  ?? '';
        $city      = $metaMap['_shipping_city']       ?? '';
        $postcode  = $metaMap['_shipping_postcode']   ?? '';
        $country   = $metaMap['_shipping_country']    ?? '';

        return [
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'address_1'  => $address1,
            'city'       => $city,
            'postcode'   => $postcode,
            'country'    => $country,
        ];
    }

    private function buildBillingFromLegacyMeta(array $metaMap): array
    {
        $firstName = $metaMap['_billing_first_name'] ?? '';
        $lastName  = $metaMap['_billing_last_name']  ?? '';
        $email     = $metaMap['_billing_email']      ?? '';
        $phone     = $metaMap['_billing_phone']      ?? '';
        $address1  = $metaMap['_billing_address_1']  ?? '';
        $city      = $metaMap['_billing_city']       ?? '';
        $postcode  = $metaMap['_billing_postcode']   ?? '';
        $country   = $metaMap['_billing_country']    ?? '';

        return [
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'email'      => $email,
            'phone'      => $phone,
            'address_1'  => $address1,
            'city'       => $city,
            'postcode'   => $postcode,
            'country'    => $country,
        ];
    }

    private function buildShippingFromLegacyMeta(array $metaMap): array
    {
        $firstName = $metaMap['_shipping_first_name'] ?? '';
        $lastName  = $metaMap['_shipping_last_name']  ?? '';
        $address1  = $metaMap['_shipping_address_1']  ?? '';
        $city      = $metaMap['_shipping_city']       ?? '';
        $postcode  = $metaMap['_shipping_postcode']   ?? '';
        $country   = $metaMap['_shipping_country']    ?? '';

        return [
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'address_1'  => $address1,
            'city'       => $city,
            'postcode'   => $postcode,
            'country'    => $country,
        ];
    }

    private function wxrImportComments(int $valePostId, string $entityType, SimpleXMLElement $wpComments): void
    {
        if (!$wpComments || count($wpComments) === 0) {
            return;
        }

        foreach ($wpComments as $wpComment) {
            $ns          = $wpComment->children(self::NS_WP);
            $commentId   = (int) $ns->comment_id;
            $authorName  = (string) $ns->comment_author;
            $authorEmail = (string) $ns->comment_author_email;
            $authorUrl   = (string) $ns->comment_author_url;
            $authorIp    = (string) $ns->comment_author_IP;
            $commentDate = (string) $ns->comment_date;
            $content     = (string) $ns->comment_content;
            $approved    = (int) $ns->comment_approved;
            $parentId    = (int) $ns->comment_parent;
            $userId      = (int) $ns->user_id;

            if ($parentId > 0 && isset($this->commentMap[$parentId])) {
                $parentId = $this->commentMap[$parentId];
            } else {
                $parentId = null;
            }

            $existing = $this->valeDb->fetchAssociative(
                'SELECT id FROM ' . VL_DB_PREFIX . 'comments WHERE entity_id = ? AND entity_type = ? AND author_email = ? AND created_at = ?',
                [$valePostId, $entityType, $authorEmail, $commentDate]
            );

            if ($existing) {
                $this->commentMap[$commentId] = (int) $existing['id'];
                continue;
            }

            $valeUserId = null;
            if ($userId > 0) {
                $authorLogin = (string) $ns->comment_author_login;
                $valeUserId  = $this->userMap[$authorLogin] ?? null;
            }

            $status = match ($approved) {
                1       => 'approved',
                default => 'pending',
            };

            try {
                $this->valeDb->insert(VL_DB_PREFIX . 'comments', [
                    'entity_type'    => $entityType,
                    'entity_id'      => $valePostId,
                    'parent_id'      => $parentId,
                    'author_name'    => $authorName,
                    'author_email'   => $authorEmail,
                    'author_url'     => $authorUrl ?: null,
                    'author_ip'      => $authorIp ?: null,
                    'user_id'        => $valeUserId,
                    'body'           => $content,
                    'status'         => $status,
                    'verified'       => 0,
                    'notify_replies' => 0,
                    'locale'         => 'en',
                    'created_at'     => $commentDate,
                ]);
                $this->commentMap[$commentId] = (int) $this->valeDb->lastInsertId();
            } catch (\Exception $e) {
                // Skip silently
            }
        }
    }

    private function wxrImportOrder(SimpleXMLElement $item, int $wpPostId): void
    {
        $wp          = $item->children(self::NS_WP);
        $postDate    = (string) $wp->post_date;
        $status      = $this->mapWooStatus((string) $wp->status);
        $orderNumber = 'ORD-' . $wpPostId;

        $existing = $this->valeDb->fetchAssociative(
            'SELECT id FROM ' . VL_DB_PREFIX . 'orders WHERE order_number = ?',
            [$orderNumber]
        );

        if ($existing) {
            $this->orderMap[$wpPostId] = (int) $existing['id'];
            return;
        }

        $metaMap = $this->wxrParseMeta($wp->postmeta);

        try {
            $this->valeDb->insert(VL_DB_PREFIX . 'orders', [
                'order_number'     => $orderNumber,
                'user_id'          => null,
                'status'           => $status,
                'locale'           => 'en',
                'currency'         => $metaMap['_order_currency'] ?? 'USD',
                'subtotal'         => 0,
                'tax'              => 0,
                'shipping'         => 0,
                'discount'         => 0,
                'total'            => isset($metaMap['_order_total']) ? (float) $metaMap['_order_total'] : 0,
                'coupon_code'      => null,
                'billing'          => json_encode($this->buildBillingFromWcMeta($metaMap, '')),
                'shipping_address' => json_encode($this->buildShippingFromWcMeta($metaMap)),
                'customer_note'    => null,
                'payment_method'   => $metaMap['_payment_method'] ?? null,
                'transaction_id'   => $metaMap['_transaction_id'] ?? null,
                'payment_token'    => null,
                'paid_at'          => $postDate ? date('Y-m-d H:i:s', strtotime($postDate)) : null,
                'carrier'          => null,
                'tracking_number'  => null,
                'order_type'       => 'shop',
                'plan_id'          => null,
                'subscription_id'  => null,
                'plan_name'        => null,
                'created_at'       => date('Y-m-d H:i:s'),
                'updated_at'       => date('Y-m-d H:i:s'),
            ]);
            $this->orderMap[$wpPostId] = (int) $this->valeDb->lastInsertId();
        } catch (\Exception $e) {
            // Skip silently
        }
    }

    private function wxrImportTermRelationships(SimpleXMLElement $item, int $valeEntityId, string $postType): void
    {
        $categories = $item->category;
        if (!$categories || count($categories) === 0) {
            return;
        }

        $entityType = $postType === 'product' ? 'product' : 'post';

        foreach ($categories as $cat) {
            $domain = (string) $cat->attributes()->domain;
            $slug   = (string) $cat;

            if ($domain === 'category') {
                $termId = $this->wxrFindTermIdBySlug($slug, VL_DB_PREFIX . 'categories');
                if (!$termId) {
                    continue;
                }
                $exists = $this->valeDb->fetchOne(
                    'SELECT id FROM ' . VL_DB_PREFIX . 'post_categories WHERE post_id = ? AND category_id = ?',
                    [$valeEntityId, $termId]
                );
                if ($exists) {
                    continue;
                }
                $this->valeDb->insert(VL_DB_PREFIX . 'post_categories', [
                    'post_id'     => $valeEntityId,
                    'category_id' => $termId,
                ]);
            } elseif ($domain === 'post_tag') {
                $termId = $this->wxrFindTermIdBySlug($slug, VL_DB_PREFIX . 'tags');
                if (!$termId) {
                    continue;
                }
                $exists = $this->valeDb->fetchOne(
                    'SELECT id FROM ' . VL_DB_PREFIX . 'post_tags WHERE post_id = ? AND tag_id = ?',
                    [$valeEntityId, $termId]
                );
                if ($exists) {
                    continue;
                }
                $this->valeDb->insert(VL_DB_PREFIX . 'post_tags', [
                    'post_id' => $valeEntityId,
                    'tag_id'  => $termId,
                ]);
            } elseif ($domain === 'product_cat' && $postType === 'product') {
                $termId = $this->wxrFindTermIdBySlug($slug, VL_DB_PREFIX . 'product_categories');
                if (!$termId) {
                    continue;
                }
                $exists = $this->valeDb->fetchOne(
                    'SELECT id FROM ' . VL_DB_PREFIX . 'product_to_categories WHERE product_id = ? AND category_id = ?',
                    [$valeEntityId, $termId]
                );
                if ($exists) {
                    continue;
                }
                $this->valeDb->insert(VL_DB_PREFIX . 'product_to_categories', [
                    'product_id'  => $valeEntityId,
                    'category_id' => $termId,
                ]);
            }
        }
    }

    /**
     * Import WooCommerce store-wide currency setting into Vale.
     */
    private function importWooCommerceCurrency(): void
    {
        // Only import when WooCommerce content is being imported
        if (!$this->importAll && !$this->importWooOnly) {
            return;
        }

        if ($this->wpDb === null) {
            return;
        }

        $optionsTable = $this->wpPrefix . 'options';
        if (!$this->tableExists($this->wpDb, $optionsTable)) {
            return;
        }

        $currencyCode = (string) $this->wpDb->fetchOne(
            "SELECT option_value FROM {$optionsTable} WHERE option_name = 'woocommerce_currency'"
        );
        $currencyCode = strtoupper(trim($currencyCode));

        if ($currencyCode === '') {
            return;
        }

        $refData    = require dirname(__DIR__, 2) . '/config/reference-data.php';
        $knownCodes = array_column($refData['currencies'] ?? [], 'code');
        if (!in_array($currencyCode, $knownCodes, true)) {
            $this->io->warning('Unknown WooCommerce currency: ' . $currencyCode . '. Skipping.');
            return;
        }

        $table = VL_DB_PREFIX . 'settings';
        $now   = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $keyCol = $this->valeDb->quoteIdentifier('key');
        $valCol = $this->valeDb->quoteIdentifier('value');

        $existing = $this->valeDb->fetchOne("SELECT id FROM {$table} WHERE {$keyCol} = 'currency'");
        if ($existing !== false) {
            $this->valeDb->executeStatement(
                "UPDATE {$table} SET {$valCol} = ?, updated_at = ? WHERE {$keyCol} = 'currency'",
                [$currencyCode, $now],
            );
        } else {
            $this->valeDb->executeStatement(
                "INSERT INTO {$table} (group_name, {$keyCol}, {$valCol}, type, is_autoload, created_at) VALUES (?, 'currency', ?, 'string', 1, ?)",
                ['ecommerce', $currencyCode, $now],
            );
        }

        $this->io->text('Currency imported: ' . $currencyCode);
    }

    private function importWooCommerceShopSettings(): void
    {
        // Only import when WooCommerce content is being imported
        if (!$this->importAll && !$this->importWooOnly) {
            return;
        }

        if ($this->wpDb === null) {
            return;
        }

        $optionsTable = $this->wpPrefix . 'options';
        if (!$this->tableExists($this->wpDb, $optionsTable)) {
            return;
        }

        // Helper to read a WooCommerce option
        $wooOption = function (string $name): string {
            $val = (string) $this->wpDb->fetchOne(
                "SELECT option_value FROM {$this->wpPrefix}options WHERE option_name = ?",
                [$name],
            );
            return trim($val);
        };

        // Default country: "DE:DE-RP" -> "DE"
        $defaultCountry = strtoupper(explode(':', $wooOption('woocommerce_default_country'), 2)[0]);

        // Store address
        $address1 = $wooOption('woocommerce_store_address');
        $address2 = $wooOption('woocommerce_store_address_2');
        $city     = $wooOption('woocommerce_store_city');
        $postcode = $wooOption('woocommerce_store_postcode');

        // Specific allowed countries: serialized array -> comma-separated
        $allowedRaw        = $wooOption('woocommerce_specific_allowed_countries');
        $shippingCountries = '';
        $checkoutAllowed   = '';
        if ($allowedRaw !== '') {
            $unserialized = @unserialize($allowedRaw, ['allowed_classes' => false]);
            if (is_array($unserialized)) {
                $shippingCountries = implode(',', array_map('strtoupper', array_values($unserialized)));
                $checkoutAllowed   = $shippingCountries;
            }
        }

        // If WooCommerce is set to allow all countries, leave both lists empty (no restriction).
        $allCountriesMode = $wooOption('woocommerce_allowed_countries');
        if ($allCountriesMode === 'all') {
            $shippingCountries = '';
            $checkoutAllowed   = '';
        }

        $table = VL_DB_PREFIX . 'settings';
        $now   = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // Batch save all shop settings
        $settingsMap = [
            'shop_country'               => $defaultCountry,
            'shop_address_1'             => $address1,
            'shop_address_2'             => $address2,
            'shop_city'                  => $city,
            'shop_postcode'              => $postcode,
            'shipping_countries'         => $shippingCountries,
            'checkout_allowed_countries' => $checkoutAllowed,
        ];

        $imported = [];
        $keyCol   = $this->valeDb->quoteIdentifier('key');
        $valCol   = $this->valeDb->quoteIdentifier('value');

        foreach ($settingsMap as $key => $value) {
            if ($value === '') {
                continue;
            }

            $existing = $this->valeDb->fetchOne(
                "SELECT id FROM {$table} WHERE {$keyCol} = ?",
                [$key],
            );

            if ($existing !== false) {
                // Skip existing — don't overwrite user's Vale settings
                continue;
            } else {
                $this->valeDb->executeStatement(
                    "INSERT INTO {$table} (group_name, {$keyCol}, {$valCol}, type, is_autoload, created_at) VALUES (?, ?, ?, 'string', 0, ?)",
                    ['ecommerce', $key, $value, $now],
                );
            }

            $imported[] = $key;
        }

        if ($imported !== []) {
            $this->io->text('Shop settings imported: ' . implode(', ', $imported));
        }
    }

    private function wxrFindTermIdBySlug(string $slug, string $table): ?int
    {
        $row = $this->valeDb->fetchAssociative(
            "SELECT id FROM {$table} WHERE slug = ?",
            [$slug]
        );
        return $row ? (int) $row['id'] : null;
    }

    private function wpGetObjectLanguage(int $objectId, ?string $default = null): string
    {
        $default = $default ?? $this->defaultLocale;
        if ($this->wpDb === null) {
            return $default;
        }

        try {
            // Query term_relationships and term_taxonomy to find the language associated
            $lang = $this->wpDb->fetchOne("
                SELECT t.slug
                FROM {$this->wpPrefix}term_relationships tr
                JOIN {$this->wpPrefix}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                JOIN {$this->wpPrefix}terms t ON tt.term_id = t.term_id
                WHERE tr.object_id = ? AND tt.taxonomy IN ('language', 'term_language')
                LIMIT 1
            ", [$objectId]);

            return $lang ? (string) $lang : $default;
        } catch (\Exception $e) {
            return $default;
        }
    }

    private function resolvePolylangTranslations(): void
    {
        // Only resolve when posts/content are being imported
        if (!$this->importAll && !$this->importPostsOnly) {
            return;
        }

        if ($this->wpDb === null) {
            return;
        }

        $taxTable = $this->wpPrefix . 'term_taxonomy';
        if (!$this->tableExists($this->wpDb, $taxTable)) {
            return;
        }

        if (isset($this->io)) {
            $this->io->text('Resolving Polylang translation relationships...');
        }

        try {
            // Get post translations
            $postTranslations = $this->wpDb->fetchAllAssociative("
                SELECT tt.description
                FROM {$taxTable} tt
                WHERE tt.taxonomy = 'post_translations'
            ");

            $resolvedPostCount = 0;
            foreach ($postTranslations as $row) {
                $desc = $row['description'];
                if (!$desc) {
                    continue;
                }

                $data = $this->extractSerializedArray($desc);
                if (!is_array($data)) {
                    continue;
                }

                // Map WordPress post/page/product IDs to imported Vale IDs
                $valeIds = [];
                foreach ($data as $langCode => $wpPostId) {
                    $valeId = $this->postMap[(int) $wpPostId] ?? null;
                    if ($valeId) {
                        $valeIds[$langCode] = $valeId;
                    }
                }

                if (count($valeIds) <= 1) {
                    continue;
                }

                // Designate the first one as original, the rest point to it
                $masterId = reset($valeIds);

                // Batch: fetch all product IDs once instead of per-row query
                $valeIdList = array_values(array_filter($valeIds, static fn ($v) => $v !== $masterId));
                $productIds = [];
                if ($valeIdList !== []) {
                    $placeholders = implode(',', array_fill(0, count($valeIdList), '?'));
                    $rows         = $this->valeDb->fetchAllAssociative(
                        'SELECT id FROM ' . VL_DB_PREFIX . 'products WHERE id IN (' . $placeholders . ')',
                        $valeIdList
                    );
                    $productIds = array_column($rows, 'id');
                }
                $productIdSet = array_flip($productIds);

                foreach ($valeIds as $langCode => $valeId) {
                    if ($valeId === $masterId) {
                        continue;
                    }
                    $table = isset($productIdSet[$valeId])
                        ? VL_DB_PREFIX . 'products'
                        : VL_DB_PREFIX . 'posts';

                    $this->valeDb->update($table, ['translation_of' => $masterId], ['id' => $valeId]);
                    $resolvedPostCount++;
                }
            }

            if ($resolvedPostCount > 0 && isset($this->io)) {
                $this->io->success("Resolved {$resolvedPostCount} Polylang post translation relationships.");
            }

            // Get term (category/tag) translations
            $termTranslations = $this->wpDb->fetchAllAssociative("
                SELECT tt.description
                FROM {$taxTable} tt
                WHERE tt.taxonomy = 'term_translations'
            ");

            $resolvedTermCount = 0;
            foreach ($termTranslations as $row) {
                $desc = $row['description'];
                if (!$desc) {
                    continue;
                }

                $data = $this->extractSerializedArray($desc);
                if (!is_array($data)) {
                    continue;
                }

                // Map WordPress term IDs (which might be term_taxonomy_id or term_id) to imported Vale IDs
                $valeIds = [];
                foreach ($data as $langCode => $wpTermId) {
                    $valeId = $this->termIdMap[(int) $wpTermId] ?? $this->termMap[(int) $wpTermId] ?? null;
                    if ($valeId) {
                        $valeIds[$langCode] = $valeId;
                    }
                }

                if (count($valeIds) <= 1) {
                    continue;
                }

                $masterId = reset($valeIds);
                foreach ($valeIds as $langCode => $valeId) {
                    if ($valeId === $masterId) {
                        continue;
                    }

                    // Determine if it is category, product_category or tag
                    $table = null;
                    if ($this->valeDb->fetchOne('SELECT id FROM ' . VL_DB_PREFIX . 'categories WHERE id = ?', [$valeId])) {
                        $table = VL_DB_PREFIX . 'categories';
                    } elseif ($this->valeDb->fetchOne('SELECT id FROM ' . VL_DB_PREFIX . 'tags WHERE id = ?', [$valeId])) {
                        $table = VL_DB_PREFIX . 'tags';
                    } elseif ($this->valeDb->fetchOne('SELECT id FROM ' . VL_DB_PREFIX . 'product_categories WHERE id = ?', [$valeId])) {
                        $table = VL_DB_PREFIX . 'product_categories';
                    }

                    if ($table) {
                        $this->valeDb->update($table, ['translation_of' => $masterId], ['id' => $valeId]);
                        $resolvedTermCount++;
                    }
                }
            }

            if ($resolvedTermCount > 0 && isset($this->io)) {
                $this->io->success("Resolved {$resolvedTermCount} Polylang term translation relationships.");
            }
        } catch (\Exception $e) {
            // Fails silently, no error reported to user
        }
    }

    private function resolveWxrPolylangTranslations(): void
    {
        if (empty($this->wxrTranslations)) {
            return;
        }

        if (isset($this->io)) {
            $this->io->text('Resolving Polylang translations in WXR mode...');
        }

        $resolvedPostCount = 0;
        $resolvedTermCount = 0;

        try {
            foreach ($this->wxrTranslations as $t) {
                $taxonomy = $t['taxonomy'];
                $map      = $t['data'];

                if (!is_array($map)) {
                    continue;
                }

                if ($taxonomy === 'post_translations') {
                    $valeIds = [];
                    foreach ($map as $langCode => $wpPostId) {
                        $valeId = $this->postMap[(int) $wpPostId] ?? null;
                        if ($valeId) {
                            $valeIds[$langCode] = $valeId;

                            // Also update language to match the translation key!
                            $table = $this->valeDb->fetchOne('SELECT id FROM ' . VL_DB_PREFIX . 'products WHERE id = ?', [$valeId])
                                ? VL_DB_PREFIX . 'products'
                                : VL_DB_PREFIX . 'posts';
                            $this->valeDb->update($table, ['language' => $langCode], ['id' => $valeId]);
                        }
                    }

                    if (count($valeIds) <= 1) {
                        continue;
                    }

                    $masterId = reset($valeIds);
                    foreach ($valeIds as $langCode => $valeId) {
                        if ($valeId === $masterId) {
                            continue;
                        }

                        $table = $this->valeDb->fetchOne('SELECT id FROM ' . VL_DB_PREFIX . 'products WHERE id = ?', [$valeId])
                            ? VL_DB_PREFIX . 'products'
                            : VL_DB_PREFIX . 'posts';

                        $this->valeDb->update($table, ['translation_of' => $masterId], ['id' => $valeId]);
                        $resolvedPostCount++;
                    }
                } elseif ($taxonomy === 'term_translations') {
                    $valeIds = [];
                    foreach ($map as $langCode => $wpTermId) {
                        $valeId = $this->termIdMap[(int) $wpTermId] ?? $this->termMap[(int) $wpTermId] ?? null;
                        if ($valeId) {
                            $valeIds[$langCode] = $valeId;

                            // Update language of term to match translation key!
                            $table = null;
                            if ($this->valeDb->fetchOne('SELECT id FROM ' . VL_DB_PREFIX . 'categories WHERE id = ?', [$valeId])) {
                                $table = VL_DB_PREFIX . 'categories';
                            } elseif ($this->valeDb->fetchOne('SELECT id FROM ' . VL_DB_PREFIX . 'tags WHERE id = ?', [$valeId])) {
                                $table = VL_DB_PREFIX . 'tags';
                            } elseif ($this->valeDb->fetchOne('SELECT id FROM ' . VL_DB_PREFIX . 'product_categories WHERE id = ?', [$valeId])) {
                                $table = VL_DB_PREFIX . 'product_categories';
                            }

                            if ($table) {
                                $this->valeDb->update($table, ['language' => $langCode], ['id' => $valeId]);
                            }
                        }
                    }

                    if (count($valeIds) <= 1) {
                        continue;
                    }

                    $masterId = reset($valeIds);
                    foreach ($valeIds as $langCode => $valeId) {
                        if ($valeId === $masterId) {
                            continue;
                        }

                        $table = null;
                        if ($this->valeDb->fetchOne('SELECT id FROM ' . VL_DB_PREFIX . 'categories WHERE id = ?', [$valeId])) {
                            $table = VL_DB_PREFIX . 'categories';
                        } elseif ($this->valeDb->fetchOne('SELECT id FROM ' . VL_DB_PREFIX . 'tags WHERE id = ?', [$valeId])) {
                            $table = VL_DB_PREFIX . 'tags';
                        } elseif ($this->valeDb->fetchOne('SELECT id FROM ' . VL_DB_PREFIX . 'product_categories WHERE id = ?', [$valeId])) {
                            $table = VL_DB_PREFIX . 'product_categories';
                        }

                        if ($table) {
                            $this->valeDb->update($table, ['translation_of' => $masterId], ['id' => $valeId]);
                            $resolvedTermCount++;
                        }
                    }
                }
            }

            if ($resolvedPostCount > 0 && isset($this->io)) {
                $this->io->success("Resolved {$resolvedPostCount} Polylang post translation relationships in WXR mode.");
            }
            if ($resolvedTermCount > 0 && isset($this->io)) {
                $this->io->success("Resolved {$resolvedTermCount} Polylang term translation relationships in WXR mode.");
            }
        } catch (\Exception $e) {
            // Fails silently, no error reported to user
        }
    }

    private function importSeoOnly(): void
    {
        $this->io->section('Importing SEO Metadata Only');

        try {
            $wpPosts = $this->wpDb->fetchAllAssociative("
                SELECT ID, post_name, post_title, post_type FROM {$this->wpPrefix}posts
                WHERE post_type IN ('post', 'page', 'product') AND post_status IN ('publish', 'draft', 'future', 'pending', 'private')
            ");

            if (empty($wpPosts)) {
                $this->io->note('No posts, pages, or products found in WordPress to import SEO from.');
                return;
            }

            $progressBar   = new ProgressBar($this->io, count($wpPosts));
            $importedCount = 0;

            foreach ($wpPosts as $wpPost) {
                $slug = (string) Slug::fromString($wpPost['post_name'] ?: $wpPost['post_title'] ?: 'post-' . $wpPost['ID']);
                $lang = $this->wpGetObjectLanguage($wpPost['ID']);

                // Determine Vale table and entity type
                if ($wpPost['post_type'] === 'product') {
                    $table      = VL_DB_PREFIX . 'products';
                    $entityType = 'product';
                } else {
                    $table      = VL_DB_PREFIX . 'posts';
                    $entityType = 'post';
                }

                // Look up matching record in Vale DB
                if ($entityType === 'product') {
                    $valeId = $this->valeDb->fetchOne(
                        "SELECT id FROM {$table} WHERE slug = ? AND language = ?",
                        [$slug, $lang]
                    );
                } else {
                    $valeId = $this->valeDb->fetchOne(
                        "SELECT id FROM {$table} WHERE slug = ? AND content_type = ? AND language = ?",
                        [$slug, $wpPost['post_type'], $lang]
                    );
                }

                if ($valeId) {
                    // Import SEO meta
                    $this->importSeoMeta((int) $wpPost['ID'], (int) $valeId, $entityType);
                    $importedCount++;
                }

                $progressBar->advance();
            }

            $progressBar->finish();
            $this->io->newLine(2);
            $this->io->success("Imported SEO metadata for {$importedCount} entities.");
        } catch (\Exception $e) {
            $this->io->warning('Could not import SEO metadata: ' . $e->getMessage());
        }
    }

    private function wxrImportSeoOnly(array $items): void
    {
        $this->io->section('Importing SEO Metadata Only (WXR Mode)');

        $progressBar   = new ProgressBar($this->io, count($items));
        $importedCount = 0;

        foreach ($items as $item) {
            try {
                $wp       = $item->children(self::NS_WP);
                $postType = (string) $wp->post_type;

                if (!in_array($postType, ['post', 'page', 'product'])) {
                    $progressBar->advance();
                    continue;
                }

                $postId   = (int) $wp->post_id;
                $title    = $this->cleanImportedText((string) $item->title ?: '(no title)');
                $postName = (string) $wp->post_name;
                $slug     = (string) Slug::fromString($postName ?: $title ?: 'post-' . $postId);
                $lang     = $this->wxrGetItemLanguage($item, $this->defaultLocale);

                // Determine Vale table and entity type
                if ($postType === 'product') {
                    $table      = VL_DB_PREFIX . 'products';
                    $entityType = 'product';
                } else {
                    $table      = VL_DB_PREFIX . 'posts';
                    $entityType = 'post';
                }

                // Look up matching record in Vale DB
                if ($entityType === 'product') {
                    $valeId = $this->valeDb->fetchOne(
                        "SELECT id FROM {$table} WHERE slug = ? AND language = ?",
                        [$slug, $lang]
                    );
                } else {
                    $contentType = ($postType === 'page' ? 'page' : 'post');
                    $valeId      = $this->valeDb->fetchOne(
                        "SELECT id FROM {$table} WHERE slug = ? AND content_type = ? AND language = ?",
                        [$slug, $contentType, $lang]
                    );
                }

                if ($valeId) {
                    $metaMap = $this->wxrParseMeta($wp->postmeta);
                    $this->wxrImportPostSeoMeta((int) $valeId, $metaMap);
                    $importedCount++;
                }
            } catch (\Exception $e) {
                // Fails silently
            }
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->io->newLine(2);
        $this->io->success("Imported SEO metadata for {$importedCount} entities from WXR file.");
    }

    private function wxrGetItemLanguage(SimpleXMLElement $item, ?string $default = null): string
    {
        $default = $default ?? $this->defaultLocale;
        if (isset($item->category)) {
            foreach ($item->category as $cat) {
                $domain = (string) $cat->attributes()->domain;
                if ($domain === 'language') {
                    $nicename = (string) $cat->attributes()->nicename;
                    if ($nicename !== '') {
                        return $nicename;
                    }
                    return (string) $cat;
                }
            }
        }
        return $default;
    }

    private function extractSerializedArray(string ...$strings): ?array
    {
        foreach ($strings as $str) {
            $str = trim($str);
            if (str_starts_with($str, 'a:')) {
                $data = @unserialize($str, ['allowed_classes' => false]);
                if (is_array($data)) {
                    return $data;
                }
            }
        }
        return null;
    }

    private function getDefaultFallbackLocale(): string
    {
        try {
            $default = $this->valeDb->fetchOne("SELECT " . $this->valeDb->quoteIdentifier('value') . " FROM " . VL_DB_PREFIX . "settings WHERE " . $this->valeDb->quoteIdentifier('key') . " = 'default_locale'");
            if ($default) {
                return (string) $default;
            }
        } catch (\Exception $e) {
            // Ignore
        }
        return (string) env('APP_LOCALE', 'en');
    }

    /**
     * Extract real WooCommerce coupon codes from HPOS order meta/items.
     *
     * @return string|null
     */
    private function extractWcCouponCodes(int $orderId, array $metaMap): ?string
    {
        // WooCommerce stores coupon codes in _coupon_codes meta (comma-separated)
        if (!empty($metaMap['_coupon_codes'])) {
            $codes = array_filter(array_map('trim', explode(',', (string) $metaMap['_coupon_codes'])));
            if ($codes !== []) {
                return implode(', ', $codes);
            }
        }

        // Fallback: query wc_order_items for coupon type items
        try {
            $couponItems = $this->wpDb->fetchAllAssociative(
                "SELECT order_item_name FROM {$this->wpPrefix}wc_order_items
                 WHERE order_id = ? AND order_item_type = 'coupon'",
                [$orderId]
            );
            $codes = array_filter(array_map('trim', array_column($couponItems, 'order_item_name')));
            if ($codes !== []) {
                return implode(', ', $codes);
            }
        } catch (\Exception $e) {
            // Table may not exist or query failed
        }

        return null;
    }

    /**
     * Extract real WooCommerce coupon codes from legacy (posts table) order meta.
     *
     * @return string|null
     */
    private function extractLegacyWcCouponCodes(int $orderId, array $metaMap): ?string
    {
        // WooCommerce stores coupon codes in _coupon_codes meta
        if (!empty($metaMap['_coupon_codes'])) {
            $codes = array_filter(array_map('trim', explode(',', (string) $metaMap['_coupon_codes'])));
            if ($codes !== []) {
                return implode(', ', $codes);
            }
        }

        // Fallback: _order_discounts may contain a coupon code in legacy installs
        if (!empty($metaMap['_order_discounts'])) {
            $discount = trim((string) $metaMap['_order_discounts']);
            if ($discount !== '' && $discount !== '0' && $discount !== '0.00') {
                return $discount;
            }
        }

        return null;
    }
}
