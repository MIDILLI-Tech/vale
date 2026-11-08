<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Commands;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use ValeApp\App\Commands\DbOptimizeCommand;
use ValeApp\Domain\Comment\Repository\CommentRepositoryInterface;
use ValeApp\Domain\Content\Repository\RevisionRepositoryInterface;
use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
use ValeApp\Infrastructure\Media\ImageProcessor;
use ValeApp\Infrastructure\Media\MediaUploadService;

final class DbOptimizeCommandTest extends TestCase
{
    public function testDbOptimizeOnSqlitePlatform(): void
    {
        $db        = $this->createMock(Connection::class);
        $platform  = new SQLitePlatform();
        $revisions = $this->createStub(RevisionRepositoryInterface::class);
        $comments  = $this->createStub(CommentRepositoryInterface::class);
        $media     = $this->createStub(MediaRepositoryInterface::class);

        $uploadService = new MediaUploadService(
            $media,
            new ImageProcessor(),
            ['upload_dir' => sys_get_temp_dir()],
        );

        $db->method('getDatabasePlatform')->willReturn($platform);
        $db->expects($this->once())
            ->method('executeStatement')
            ->with('VACUUM');

        $command = new DbOptimizeCommand($db, $revisions, $comments, $media, $uploadService);
        $input   = new ArrayInput(['--optimize-tables' => true]);
        $output  = new BufferedOutput();

        $exitCode = $command->run($input, $output);

        $this->assertSame(0, $exitCode);
        $outputText = $output->fetch();
        $this->assertStringContainsString('Optimized database (VACUUM executed)', $outputText);
    }
}
