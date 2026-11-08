<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure\Media;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Media\Entity\Media;
use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Infrastructure\Media\ImageProcessor;
use ValeApp\Infrastructure\Media\MediaUploadService;

/**
 * Behaviour matrix for MediaUploadService::uploadBatch().
 *
 * Two independent settings drive the outcome:
 *  - use_original_filename OFF → files stored under random hex names, so
 *    duplicates are detected by content hash (skip / replace-by-hash).
 *  - use_original_filename ON  → files keep their (slugged) name, so conflicts
 *    are resolved by name: suffix (-1, -2), replace, skip.
 */
final class MediaUploadServiceTest extends TestCase
{
    private string $uploadDir = '';

    private const PNG_A_RGB = [210, 60, 60];
    private const PNG_B_RGB = [40, 120, 220];
    private const PNG_C_RGB = [30, 200, 90];

    protected function setUp(): void
    {
        if (!function_exists('imagepng')) {
            $this->markTestSkipped('GD extension required to fabricate PNG fixtures.');
        }
        if (!class_exists(\finfo::class)) {
            $this->markTestSkipped('fileinfo extension required for MIME detection.');
        }
        $this->uploadDir = sys_get_temp_dir() . '/vl_media_test_' . bin2hex(random_bytes(6));
        mkdir($this->uploadDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->uploadDir);
    }

    // ── OFF (random names) ────────────────────────────────────────────────

    public function testRandomNameFirstUploadKeepsOriginalName(): void
    {
        $repo    = new ArrayMediaRepository();
        $service = $this->makeService($repo, false);
        $pngA    = self::pngBytes(self::PNG_A_RGB);

        $result = $this->upload($service, 'kamil.png', $pngA, 'replace');

        $this->assertSame([], $result['errors']);
        $this->assertCount(1, $result['uploaded']);
        $row = $repo->findById(1);
        $this->assertNotNull($row);
        $this->assertSame('kamil.png', $row->getOriginalName());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}\.png$/', $row->getFilename());
        $this->assertSame($row->getPath(), $row->getFilename());
        $this->assertSame($pngA, file_get_contents($this->uploadDir . '/' . $row->getPath()));
        $this->assertSame(hash('sha256', $pngA), $row->getContentHash());
    }

    public function testOffSkipSameContentIsSkipped(): void
    {
        $repo    = new ArrayMediaRepository();
        $service = $this->makeService($repo, false);
        $pngA    = self::pngBytes(self::PNG_A_RGB);

        $this->upload($service, 'kamil.png', $pngA, 'skip');
        $second = $this->upload($service, 'kamil.png', $pngA, 'skip');

        $this->assertCount(1, $second['skipped']);
        $this->assertSame('Duplicate content', $second['skipped'][0]['reason']);
        $this->assertCount(1, $repo->all());
    }

    public function testOffSkipDifferentContentCreatesNewRecord(): void
    {
        $repo    = new ArrayMediaRepository();
        $service = $this->makeService($repo, false);

        $this->upload($service, 'kamil.png', self::pngBytes(self::PNG_A_RGB), 'skip');
        $second = $this->upload($service, 'kamil.png', self::pngBytes(self::PNG_B_RGB), 'skip');

        $this->assertSame([], $second['skipped']);
        $this->assertSame([], $second['errors']);
        $this->assertCount(2, $repo->all());
        $paths = $repo->allPaths();
        $this->assertCount(2, $paths);
        $this->assertCount(2, array_unique($paths));
    }

    public function testOffSkipDedupesAcrossDifferentOriginalNames(): void
    {
        $repo    = new ArrayMediaRepository();
        $service = $this->makeService($repo, false);
        $pngA    = self::pngBytes(self::PNG_A_RGB);

        $this->upload($service, 'cat.png', $pngA, 'skip');
        $second = $this->upload($service, 'kamil.png', $pngA, 'skip');

        $this->assertCount(1, $second['skipped']);
        $this->assertCount(1, $repo->all());
    }

    public function testOffReplaceUpdatesRecordInPlace(): void
    {
        $repo    = new ArrayMediaRepository();
        $service = $this->makeService($repo, false);
        $pngA    = self::pngBytes(self::PNG_A_RGB);
        $pngB    = self::pngBytes(self::PNG_B_RGB);

        $first  = $this->upload($service, 'kamil.png', $pngA, 'replace');
        $path   = $first['uploaded'][0]->getPath();
        $second = $this->upload($service, 'kamil.png', $pngB, 'replace');

        $this->assertSame([], $second['errors']);
        $this->assertCount(1, $second['overwritten']);
        $this->assertCount(1, $repo->all());
        $row = $repo->findById(1);
        $this->assertNotNull($row);
        // Same physical path is kept and the content is swapped in place.
        $this->assertSame($path, $row->getPath());
        $this->assertSame($pngB, file_get_contents($this->uploadDir . '/' . $row->getPath()));
        $this->assertSame(hash('sha256', $pngB), $row->getContentHash());
        $this->assertSame('kamil.png', $row->getOriginalName());
    }

    public function testOffReplacePicksNewestRecordWhenNameIsShared(): void
    {
        $repo    = new ArrayMediaRepository();
        $service = $this->makeService($repo, false);
        $pngA    = self::pngBytes(self::PNG_A_RGB);
        $pngB    = self::pngBytes(self::PNG_B_RGB);
        $pngC    = self::pngBytes(self::PNG_C_RGB);

        $older = $this->seed($repo, 'abc123.png', 'kamil.png', $pngA);
        $newer = $this->seed($repo, 'def456.png', 'kamil.png', $pngB);

        $second = $this->upload($service, 'kamil.png', $pngC, 'replace');

        $this->assertCount(1, $second['overwritten']);
        $this->assertSame($newer->getId(), $second['overwritten'][0]['id']);
        // Older file untouched, newer file replaced with new content.
        $this->assertSame($pngA, file_get_contents($this->uploadDir . '/' . $older->getPath()));
        $this->assertSame($pngC, file_get_contents($this->uploadDir . '/' . $newer->getPath()));
    }

    // ── ON (original names kept) ──────────────────────────────────────────

    public function testOnSuffixKeepsBothFiles(): void
    {
        $repo    = new ArrayMediaRepository();
        $service = $this->makeService($repo, true);
        $pngA    = self::pngBytes(self::PNG_A_RGB);
        $pngB    = self::pngBytes(self::PNG_B_RGB);

        $this->upload($service, 'kamil.png', $pngA, 'suffix');
        $second = $this->upload($service, 'kamil.png', $pngB, 'suffix');

        $this->assertSame([], $second['errors']);
        $this->assertCount(2, $repo->all());
        $paths = $repo->allPaths();
        sort($paths);
        $this->assertSame(['kamil-1.png', 'kamil.png'], $paths);
        $this->assertFileExists($this->uploadDir . '/kamil.png');
        $this->assertFileExists($this->uploadDir . '/kamil-1.png');
    }

    public function testOnSkipSkipsWhenNameAlreadyExists(): void
    {
        $repo    = new ArrayMediaRepository();
        $service = $this->makeService($repo, true);
        $pngA    = self::pngBytes(self::PNG_A_RGB);
        $pngB    = self::pngBytes(self::PNG_B_RGB);

        $this->upload($service, 'kamil.png', $pngA, 'skip');
        $second = $this->upload($service, 'kamil.png', $pngB, 'skip');

        $this->assertCount(1, $second['skipped']);
        $this->assertSame('File already exists', $second['skipped'][0]['reason']);
        $this->assertCount(1, $repo->all());
        // Original content stays untouched.
        $row = $repo->findById(1);
        $this->assertNotNull($row);
        $this->assertSame($pngA, file_get_contents($this->uploadDir . '/' . $row->getPath()));
    }

    public function testOnSkipDoesNotDedupeSameContentUnderFreeName(): void
    {
        $repo    = new ArrayMediaRepository();
        $service = $this->makeService($repo, true);
        $pngA    = self::pngBytes(self::PNG_A_RGB);

        $this->upload($service, 'cat.png', $pngA, 'skip');
        $second = $this->upload($service, 'kamil.png', $pngA, 'skip');

        // Name-based semantics: identical content under a different (free) name
        // is a new file, matching WordPress-style behaviour.
        $this->assertSame([], $second['skipped']);
        $this->assertSame([], $second['errors']);
        $this->assertCount(2, $repo->all());
    }

    public function testOnReplaceOverwritesFileAndUpdatesRecord(): void
    {
        $repo    = new ArrayMediaRepository();
        $service = $this->makeService($repo, true);
        $pngA    = self::pngBytes(self::PNG_A_RGB);
        $pngB    = self::pngBytes(self::PNG_B_RGB);

        $first = $this->upload($service, 'kamil.png', $pngA, 'replace');
        $this->assertSame(1, $first['uploaded'][0]->getId());

        $second = $this->upload($service, 'kamil.png', $pngB, 'replace');

        $this->assertSame([], $second['errors']);
        $this->assertCount(1, $second['overwritten']);
        $this->assertCount(1, $repo->all());
        $row = $repo->findById(1);
        $this->assertNotNull($row);
        $this->assertSame('kamil.png', $row->getPath());
        $this->assertSame($pngB, file_get_contents($this->uploadDir . '/' . $row->getPath()));
        $this->assertSame(hash('sha256', $pngB), $row->getContentHash());
    }

    public function testOnReplaceFallsBackToRecordInOlderFolder(): void
    {
        // The existing record lives without a YYYY/MM folder (uploaded while the
        // folder setting was off). A re-upload now — with folders on — must
        // replace that record instead of creating a duplicate in the current
        // month folder.
        $repo = new ArrayMediaRepository();
        $pngA = self::pngBytes(self::PNG_A_RGB);
        $pngB = self::pngBytes(self::PNG_B_RGB);
        $old  = $this->seed($repo, 'kamil.png', 'kamil.png', $pngA);

        $config                           = $this->baseConfig();
        $config['use_year_month_folders'] = true;
        $service                          = new MediaUploadService(
            $repo,
            new ImageProcessor(),
            $config
        );

        $second = $this->upload($service, 'kamil.png', $pngB, 'replace');

        $this->assertSame([], $second['errors']);
        $this->assertCount(1, $second['overwritten']);
        $this->assertCount(1, $repo->all());
        $this->assertSame($old->getId(), $second['overwritten'][0]['id']);
        // Record keeps its own (folder-less) path and the file is overwritten there.
        $this->assertSame('kamil.png', $old->getPath());
        $this->assertSame($pngB, file_get_contents($this->uploadDir . '/' . $old->getPath()));
    }

    public function testReplaceDeletesStaleDerivativeFiles(): void
    {
        $repo = new ArrayMediaRepository();
        $pngA = self::pngBytes(self::PNG_A_RGB);
        $pngB = self::pngBytes(self::PNG_B_RGB);
        $old  = $this->seed($repo, 'kamil.png', 'kamil.png', $pngA, [
            'small'  => 'kamil-small.png',
            'medium' => 'kamil-medium.png',
        ]);
        file_put_contents($this->uploadDir . '/kamil-small.png', 'small-bytes');
        file_put_contents($this->uploadDir . '/kamil-medium.png', 'medium-bytes');

        $service = $this->makeService($repo, true);
        $second  = $this->upload($service, 'kamil.png', $pngB, 'replace');

        $this->assertCount(1, $second['overwritten']);
        $this->assertFileDoesNotExist($this->uploadDir . '/kamil-small.png');
        $this->assertFileDoesNotExist($this->uploadDir . '/kamil-medium.png');
        $row = $repo->findById($old->getId());
        $this->assertNotNull($row);
        $this->assertSame([], $row->getThumbnails());
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** @param array<int, int> $rgb */
    private static function pngBytes(array $rgb): string
    {
        $img = imagecreatetruecolor(1, 1);
        $col = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
        imagefill($img, 0, 0, $col);

        $tmp = tempnam(sys_get_temp_dir(), 'vl_png_');
        imagepng($img, $tmp);

        $bytes = (string) file_get_contents($tmp);
        unlink($tmp);

        return $bytes;
    }

    /** @return array<string, mixed> */
    private function baseConfig(): array
    {
        return [
            'upload_dir'              => $this->uploadDir,
            'use_year_month_folders'  => false,
            'use_original_filename'   => false,
            'allowed_extensions'      => ['png', 'jpg', 'webp'],
            'allowed_mime_types'      => ['image/png', 'image/jpeg', 'image/webp'],
            'enable_image_sanitize'   => false,
            'enable_webp'             => false,
            'thumbnails'              => ['sizes' => []],
            'max_file_size'           => 10485760,
            'max_file_count'          => 100,
            'max_total_size'          => 0,
            'rate_limit'              => 1000,
        ];
    }

    private function makeService(
        ArrayMediaRepository $repo,
        bool $useOriginalName,
    ): MediaUploadService {
        $config                          = $this->baseConfig();
        $config['use_original_filename'] = $useOriginalName;

        return new MediaUploadService($repo, new ImageProcessor(), $config);
    }

    /**
     * @param array<string, string> $thumbs
     */
    private function seed(
        ArrayMediaRepository $repo,
        string $filename,
        string $origName,
        string $bytes,
        array $thumbs = [],
    ): Media {
        $media = new Media(1, $filename, $origName, 'image/png', strlen($bytes), $filename);
        $media->setThumbnails($thumbs);
        $media->setContentHash(hash('sha256', $bytes));
        file_put_contents($this->uploadDir . '/' . $filename, $bytes);
        return $repo->seed($media);
    }

    /**
     * @return array{
     *     uploaded: Media[],
     *     skipped: array<int, array{name: string, reason: string}>,
     *     overwritten: array<int, array{name: string, id: int}>,
     *     errors: array<int, array{file: string, error: string}>
     * }
     */
    private function upload(
        MediaUploadService $service,
        string $name,
        string $bytes,
        string $mode,
    ): array {
        $tmp = tempnam(sys_get_temp_dir(), 'vl_up_');
        file_put_contents($tmp, $bytes);

        $result = $service->uploadBatch([
            [
                'tmp_name' => $tmp,
                'name'     => $name,
                'type'     => 'image/png',
                'size'     => strlen($bytes),
                'error'    => UPLOAD_ERR_OK,
            ],
        ], 1, $mode);

        @unlink($tmp);
        return $result;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
