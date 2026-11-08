<?php

declare(strict_types=1);

namespace ValeApp\Application\Update;

/**
 * Value object returned by UpdateService::apply().
 */
final class UpdateResult
{
    public string $newVersion              = '';
    public string $extractedTo             = '';
    public int $copiedFiles                = 0;
    public int $clearedCacheFiles          = 0;
    public string $migrationOutput         = '';
    public string $backupDir               = '';
    public string $verifiedSignatureMethod = '';
    public bool $rolledBack                = false;
}
