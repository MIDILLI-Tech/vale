<?php

declare(strict_types=1);

namespace ValeApp\App\Commands;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use ValeApp\App\Commands\Concerns\BackfillsMediaVariants;
use ValeApp\App\Commands\Concerns\FlushesPageCache;
use ValeApp\Application\Media\MediaOwnerResolver;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Infrastructure\Auth\PasswordHasher;
use ValeApp\Infrastructure\Cache\PageCache;
use ValeApp\Infrastructure\Content\ButtonBlockConverter;
use ValeApp\Infrastructure\Content\GhostAssetExtractor;
use ValeApp\Infrastructure\Media\MediaScanService;

/**
 * Import content from a Ghost JSON export file.
 */
final class ImportGhostCommand extends Command
{
    use BackfillsMediaVariants;
    use FlushesPageCache;

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif'];

    private SymfonyStyle $io;
    private bool $dryRun   = false;
    private array $userMap = [];
    private array $tagMap  = [];
    private array $postMap = [];

    /** @var array<string, array{id: int, path: string}> normalized URL => media row */
    private array $mediaMap = [];

    private ?MediaOwnerResolver $mediaOwner = null;

    public function __construct(
        private readonly Connection $valeDb,
        private readonly PasswordHasher $hasher,
        private readonly ?MediaScanService $scanner = null,
        private readonly ?PageCache $pageCache = null,
    ) {
        parent::__construct();
    }

    /**
     * Owner for imported media rows — `media.user_id` is a NOT NULL FK to
     * `users`, so the id must be resolved instead of hard-coded.
     */
    private function mediaOwner(): MediaOwnerResolver
    {
        return $this->mediaOwner ??= new MediaOwnerResolver($this->valeDb);
    }

    protected function configure(): void
    {
        $this
            ->setName('import:ghost')
            ->setDescription('Import content from a Ghost JSON export file')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path to Ghost JSON export file')
            ->addOption('download-media', null, InputOption::VALUE_NONE, 'Download feature/OG/inline images and rewrite post HTML')
            ->addOption('ghost-url', null, InputOption::VALUE_REQUIRED, 'Base URL of the Ghost site (resolves relative /content/images/ URLs)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview changes without writing to database');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io     = new SymfonyStyle($input, $output);
        $this->io->title('Vale Ghost Importer');
        $this->dryRun = (bool) $input->getOption('dry-run');

        if ($input->isInteractive()) {
            $this->io->section('Interactive Import — Answer a few questions');
        }

        $filePath = $input->getOption('file');
        if (!$filePath || !file_exists($filePath)) {
            if (!$input->isInteractive()) {
                $this->io->error("Ghost export file not found: {$filePath}");
                return Command::FAILURE;
            }
            $filePath = trim((string) $this->io->ask('Path to the Ghost JSON export file'));
            if ($filePath === '' || !file_exists($filePath)) {
                $this->io->error("Ghost export file not found: {$filePath}");
                return Command::FAILURE;
            }
        }

        $data = json_decode(file_get_contents($filePath), true);
        if (!$data || !isset($data['db'][0]['data'])) {
            $this->io->error('Invalid Ghost export file format.');
            return Command::FAILURE;
        }

        $ghostData = $data['db'][0]['data'];
        $posts     = $ghostData['posts'] ?? [];

        $downloadMedia = (bool) $input->getOption('download-media');
        $baseUrl       = $input->getOption('ghost-url');

        if (!$input->getOption('download-media') && $input->isInteractive()) {
            $downloadMedia = $this->io->confirm(
                'Download feature/OG/inline images and rewrite post HTML?',
                false
            );
        }

        if ($downloadMedia && $baseUrl === null && $input->isInteractive()) {
            $baseUrl = trim((string) $this->io->ask(
                'Ghost site base URL (optional, resolves relative image URLs)'
            ));
            if ($baseUrl === '') {
                $baseUrl = null;
            }
        }

        if (!$input->getOption('dry-run') && $input->isInteractive()) {
            $this->dryRun = $this->io->confirm(
                'Run as a dry run (no changes written to the database)?',
                false
            );
        }

        if ($this->dryRun) {
            $this->io->note('DRY RUN — no changes will be written to the database.');
        }

        if ($downloadMedia && $this->dryRun) {
            $this->io->note(
                '--download-media is skipped during dry run (no files are downloaded).'
            );
        } elseif ($downloadMedia && $posts !== []) {
            $this->importMedia($posts, is_string($baseUrl) ? rtrim($baseUrl, '/') : null);
        } elseif ($downloadMedia) {
            $this->io->note('--download-media given but no posts found in export.');
        }

        $this->importUsers($ghostData['users'] ?? []);
        $this->importTags($ghostData['tags'] ?? []);
        $this->importPosts(
            $posts,
            $ghostData['posts_tags'] ?? [],
            is_string($baseUrl) ? rtrim($baseUrl, '/') : null,
            $ghostData['posts_authors'] ?? []
        );

        $this->backfillMediaVariants();

        $this->io->section('Import Summary');
        $this->io->table(['Module', 'Status'], [
            ['Users', count($ghostData['users'] ?? []) === 0 ? 'none found (skipped)' : 'imported'],
            ['Tags', count($ghostData['tags'] ?? []) === 0 ? 'none found (skipped)' : 'imported'],
            ['Posts and pages', count($posts) === 0 ? 'none found (skipped)' : 'imported'],
            ['Media files', $downloadMedia ? 'downloaded from URLs' : 'skipped'],
        ]);

        $this->flushPageCache($this->pageCache, $this->dryRun, $this->io);

        $this->io->success('Ghost import completed successfully!' . ($this->dryRun ? ' (dry run)' : ''));

        return Command::SUCCESS;
    }

    /**
     * Collects image references from all posts, downloads them and builds
     * the URL => [media id, local path] map used for HTML rewriting.
     *
     * @param array<int, array<string, mixed>> $posts
     */
    private function importMedia(array $posts, ?string $baseUrl): void
    {
        $this->io->section('Collecting Media References');

        // normalized URL => every raw variant seen across the export
        $normalized = [];
        foreach ($posts as $post) {
            foreach (GhostAssetExtractor::extractImageUrls($post) as $raw) {
                $norm = GhostAssetExtractor::normalizeImageUrl($raw, $baseUrl);
                if ($norm === null) {
                    continue;
                }
                $normalized[$norm][] = $raw;
            }
        }

        if ($normalized === []) {
            $this->io->text('No downloadable images found.');
            return;
        }

        if ($baseUrl === null && $this->hasRootRelative(array_merge(...array_values($normalized)))) {
            $this->io->warning('Relative image URLs found but --ghost-url is not set. Image downloads will use URLs as-is. Pass --ghost-url to resolve relative paths.');
        }

        $this->io->section('Downloading Media');
        $progressBar = new ProgressBar($this->io, count($normalized));
        $failures    = 0;

        foreach ($normalized as $url => $rawVariants) {
            try {
                $local = $this->downloadGhostImage($url);
            } catch (\Throwable $e) {
                $local = null;
                $this->io->warning("Download failed: {$url} — " . $e->getMessage());
            }

            if ($local === null) {
                $failures++;
                $progressBar->advance();
                continue;
            }

            foreach ($rawVariants as $raw) {
                $this->mediaMap[$raw] = $local;
            }
            // Also map the canonical absolute form so feature_image matches
            $this->mediaMap[$url] = $local;

            $progressBar->advance();
        }
        $progressBar->finish();
        $this->io->newLine(2);

        $count = count(array_unique(array_map(static fn ($m) => $m['id'], $this->mediaMap)));
        $this->io->text(sprintf('Imported %d media file(s)%s.', $count, $failures > 0 ? ", {$failures} failed" : ''));
    }

    /** @param string[] $rawUrls */
    private function hasRootRelative(array $rawUrls): bool
    {
        foreach ($rawUrls as $u) {
            if (str_starts_with($u, '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Downloads one image into storage/uploads and registers it in vl_media.
     *
     * @return array{id: int, path: string}|null
     */
    private function downloadGhostImage(string $url): ?array
    {
        $uploadsDir = dirname(__DIR__, 2) . '/storage/uploads';
        $path       = (string) (parse_url($url, PHP_URL_PATH) ?? '');

        if (str_contains($path, '/content/images/')) {
            $relativePath = substr($path, strpos($path, '/content/images/') + strlen('/content/images/'));
        } else {
            $relativePath = 'ghost/' . basename($path);
        }

        $relativePath = str_replace(['\\', '..'], ['/', ''], ltrim($relativePath, '/'));
        $filename     = basename($relativePath);
        if ($filename === '' || !in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), self::IMAGE_EXTENSIONS, true)) {
            return null;
        }

        $destPath = $uploadsDir . '/' . $relativePath;
        if (!is_dir(dirname($destPath))) {
            @mkdir(dirname($destPath), 0755, true);
        }

        $freshDownload = false;
        if (!file_exists($destPath)) {
            $context = stream_context_create([
                'http' => ['timeout' => 30, 'user_agent' => 'Vale Ghost Importer/1.0'],
            ]);
            $content = @file_get_contents($url, false, $context);
            if ($content === false) {
                return null;
            }
            if (@file_put_contents($destPath, $content) === false) {
                return null;
            }
            $freshDownload = true;
        }

        // MIME sanity check on real bytes
        $mime  = mime_content_type($destPath) ?: '';
        $valid = str_starts_with($mime, 'image/')
            || (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'svg' && str_contains($mime, 'xml'));
        if (!$valid) {
            @unlink($destPath);
            return null;
        }

        // Apply the site's media whitelist + SVG/raster sanitization to freshly
        // downloaded files (registered files were already validated on import).
        if ($freshDownload && $this->scanner !== null) {
            try {
                $mime = $this->scanner->sanitizeFile($destPath);
            } catch (\Throwable $e) {
                @unlink($destPath);
                return null;
            }
        }

        $existing = $this->valeDb->fetchAssociative(
            'SELECT id FROM ' . VL_DB_PREFIX . 'media WHERE path = ?',
            [$relativePath]
        );
        if ($existing !== false) {
            return ['id' => (int) $existing['id'], 'path' => $relativePath];
        }

        if ($this->dryRun) {
            return ['id' => 0, 'path' => $relativePath];
        }

        $mediaUserId = $this->mediaOwner()->resolve();
        if ($mediaUserId === null) {
            return null;
        }

        $this->valeDb->insert(VL_DB_PREFIX . 'media', [
            'user_id'       => $mediaUserId,
            'filename'      => $filename,
            'original_name' => $filename,
            'mime_type'     => $mime,
            'size'          => (int) filesize($destPath),
            'path'          => $relativePath,
            'disk'          => 'local',
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        return ['id' => (int) $this->valeDb->lastInsertId(), 'path' => $relativePath];
    }

    private function importUsers(array $users): void
    {
        $this->io->section('Importing Users');
        if (count($users) === 0) {
            $this->io->note('No users found.');
            return;
        }
        $progressBar = new ProgressBar($this->io, count($users));

        foreach ($users as $user) {
            try {
                $existing = $this->valeDb->fetchAssociative(
                    'SELECT id FROM ' . VL_DB_PREFIX . 'users WHERE email = ?',
                    [$user['email']]
                );

                if ($existing) {
                    $this->userMap[$user['id']] = (int) $existing['id'];
                } else {
                    if (!$this->dryRun) {
                        $this->valeDb->insert(VL_DB_PREFIX . 'users', [
                            'email'      => $user['email'],
                            'password'   => $this->hasher->hash(bin2hex(random_bytes(16))),
                            'name'       => $user['name'] ?: $user['slug'],
                            'created_at' => $this->ghostDate($user['created_at']) ?? date('Y-m-d H:i:s'),
                            'is_active'  => 0,
                        ]);
                        $newId = (int) $this->valeDb->lastInsertId();

                        $subscriberRole = $this->valeDb->fetchOne('SELECT id FROM ' . VL_DB_PREFIX . 'roles WHERE slug = ?', ['subscriber']);
                        if ($subscriberRole) {
                            $this->valeDb->insert(VL_DB_PREFIX . 'user_roles', [
                                'user_id' => $newId,
                                'role_id' => $subscriberRole,
                            ]);
                        }

                        $this->userMap[$user['id']] = $newId;
                    } else {
                        // Dry run: no row is written, so the id cannot be known yet.
                        $this->userMap[$user['id']] = 0;
                    }
                }
            } catch (\Exception $e) {
                $this->io->warning("Failed to import user {$user['email']}: " . $e->getMessage());
            }
            $progressBar->advance();
        }
        $progressBar->finish();
        $this->io->newLine(2);
    }

    private function importTags(array $tags): void
    {
        $this->io->section('Importing Tags');
        if (count($tags) === 0) {
            $this->io->note('No tags found.');
            return;
        }
        $progressBar = new ProgressBar($this->io, count($tags));

        foreach ($tags as $tag) {
            try {
                $slug = (string) Slug::fromString($tag['slug'] ?: $tag['name']);

                $existing = $this->valeDb->fetchAssociative(
                    'SELECT id FROM ' . VL_DB_PREFIX . 'tags WHERE slug = ? AND language = ?',
                    [$slug, 'en']
                );

                if ($existing) {
                    $this->tagMap[$tag['id']] = (int) $existing['id'];
                } else {
                    if (!$this->dryRun) {
                        $this->valeDb->insert(VL_DB_PREFIX . 'tags', [
                            'name'       => $tag['name'],
                            'slug'       => $slug,
                            'language'   => 'en',
                            'created_at' => $this->ghostDate($tag['created_at']) ?? date('Y-m-d H:i:s'),
                        ]);
                        $this->tagMap[$tag['id']] = (int) $this->valeDb->lastInsertId();
                    } else {
                        // Dry run: no row is written, so the id cannot be known yet.
                        $this->tagMap[$tag['id']] = 0;
                    }
                }
            } catch (\Exception $e) {
                $this->io->warning("Failed to import tag: {$tag['name']} — " . $e->getMessage());
            }
            $progressBar->advance();
        }
        $progressBar->finish();
        $this->io->newLine(2);

        // Re-fetch real IDs if dry run (so the tag mapping exists for post processing)
        if ($this->dryRun) {
            foreach ($tags as $tag) {
                if (!isset($this->tagMap[$tag['id']])) {
                    continue;
                }
                $slug     = (string) Slug::fromString($tag['slug'] ?: $tag['name']);
                $existing = $this->valeDb->fetchAssociative(
                    'SELECT id FROM ' . VL_DB_PREFIX . 'tags WHERE slug = ? AND language = ?',
                    [$slug, 'en']
                );
                $this->tagMap[$tag['id']] = $existing ? (int) $existing['id'] : 0;
            }
        }
    }

    private function importPosts(array $posts, array $postsTags, ?string $baseUrl = null, array $postsAuthors = []): void
    {
        $this->io->section('Importing Posts and Pages');
        if (count($posts) === 0) {
            $this->io->note('No posts or pages found.');
            return;
        }
        $progressBar = new ProgressBar($this->io, count($posts));

        // Build tag lookup: post_id => [tag_id, ...]
        $postTagMap = [];
        foreach ($postsTags as $pt) {
            $postTagMap[$pt['post_id']][] = $pt['tag_id'];
        }

        // Ghost stores authorship in the posts_authors join table
        // (posts rows carry no author_id). Keep the primary author
        // (lowest sort_order) per post; Vale supports a single author.
        $postAuthorMap    = [];
        $postAuthorOrder  = [];
        foreach ($postsAuthors as $pa) {
            $postId   = $pa['post_id']   ?? null;
            $authorId = $pa['author_id'] ?? null;
            if ($postId === null || $authorId === null || $authorId === '') {
                continue;
            }
            $order = (int) ($pa['sort_order'] ?? 0);
            if (!isset($postAuthorMap[$postId]) || $order < $postAuthorOrder[$postId]) {
                $postAuthorMap[$postId]   = $authorId;
                $postAuthorOrder[$postId] = $order;
            }
        }

        foreach ($posts as $post) {
            try {
                $type   = $post['type']   === 'page' ? 'page' : 'post';
                $status = $post['status'] === 'published' ? 'published' : 'draft';

                $slug = (string) Slug::fromString($post['slug'] ?: $post['title']);

                $html = $post['html'] ?: $post['plaintext'];
                if (is_string($html) && $html !== '') {
                    if ($this->mediaMap !== []) {
                        $replacements = [];
                        foreach ($this->mediaMap as $sourceUrl => $media) {
                            $replacements[$sourceUrl] = __app_base_path() . '/uploads/' . $media['path'];
                        }
                        $html = GhostAssetExtractor::rewriteHtml($html, $replacements);
                    }
                    // Non-downloaded (or undownloadable) images still carry the
                    // __GHOST_URL__ placeholder — resolve it to the live site.
                    if ($baseUrl !== null) {
                        $html = str_replace('__GHOST_URL__', $baseUrl, $html);
                    }
                }

                $html = ButtonBlockConverter::convertGhost($html);

                $featuredImageId = null;
                if (!empty($post['feature_image']) && isset($this->mediaMap[$post['feature_image']]['id'])) {
                    $featuredImageId = $this->mediaMap[$post['feature_image']]['id'] ?: null;
                }

                $existingRow = $this->valeDb->fetchAssociative(
                    'SELECT id FROM ' . VL_DB_PREFIX . 'posts WHERE slug = ? AND content_type = ? AND language = ?',
                    [$slug, $type, 'en']
                );

                $isExisting = $existingRow !== false;

                if ($isExisting) {
                    $newPostId                  = (int) $existingRow['id'];
                    $this->postMap[$post['id']] = $newPostId;

                    if (!$this->dryRun && $newPostId > 0 && $featuredImageId !== null) {
                        $this->valeDb->update(VL_DB_PREFIX . 'posts', ['featured_image_id' => $featuredImageId], ['id' => $newPostId]);
                    }
                } else {
                    if (!$this->dryRun) {
                        $ghostAuthorId = $postAuthorMap[$post['id']] ?? ($post['author_id'] ?? null);
                        $this->valeDb->insert(VL_DB_PREFIX . 'posts', [
                            'content_type'      => $type,
                            'author_id'         => ($this->userMap[$ghostAuthorId] ?? 0)
                                ?: 1,
                            'title'             => $post['title'],
                            'slug'              => $slug,
                            'excerpt'           => $post['custom_excerpt'] ?: ($post['meta_description'] ?? null),
                            'body'              => $html,
                            'status'            => $status,
                            'featured_image_id' => $featuredImageId,
                            'published_at'      => isset($post['published_at']) ? $this->ghostDate($post['published_at']) : null,
                            'created_at'        => $this->ghostDate($post['created_at'])                        ?? date('Y-m-d H:i:s'),
                            'updated_at'        => $this->ghostDate($post['updated_at'] ?? $post['created_at']) ?? date('Y-m-d H:i:s'),
                            'meta_title'        => $post['meta_title']                                          ?? null,
                            'meta_description'  => $post['meta_description']                                    ?? null,
                            'language'          => 'en',
                        ]);
                        $newPostId = (int) $this->valeDb->lastInsertId();
                    } else {
                        $newPostId = 0;
                    }
                    $this->postMap[$post['id']] = $newPostId;
                }

                // Map tags using pre-built lookup
                if (!$this->dryRun && $newPostId > 0) {
                    $tagIds = $postTagMap[$post['id']] ?? [];
                    foreach ($tagIds as $tagId) {
                        $valeTagId = ($this->tagMap[$tagId] ?? 0) ?: null;
                        if ($valeTagId !== null) {
                            try {
                                $this->valeDb->insert(VL_DB_PREFIX . 'post_tags', [
                                    'post_id' => $newPostId,
                                    'tag_id'  => $valeTagId,
                                ]);
                            } catch (\Exception) {
                                // Skip duplicate tag mapping
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->io->warning("Failed to import post: {$post['title']} — " . $e->getMessage());
            }
            $progressBar->advance();
        }
        $progressBar->finish();
        $this->io->newLine(2);
    }

    /**
     * Ghost exports store timestamps either as ISO-8601 strings (e.g.
     * "2026-09-06T11:34:21.000Z") or as ms/s epoch integers. Normalize
     * either form to a "Y-m-d H:i:s" database string (null when unset).
     */
    private function ghostDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return date('Y-m-d H:i:s', (int) ((float) $value / 1000));
        }
        $timestamp = strtotime((string) $value);

        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }
}
