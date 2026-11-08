<?php

declare(strict_types=1);

namespace ValeApp\Application\System;

final class SystemRequirementHelper
{
    /** @var string[] PDO driver extensions that are never warned about in admin. */
    private const SKIPPED_DRIVER_EXTS = ['pdo_mysql', 'pdo_pgsql', 'pdo_sqlite'];

    /**
     * @param array<string,string> $recommendedExts ext => description from reference-data.php
     * @param bool $shopEnabled whether the shop feature is enabled
     * @return array<string,string> ext => description for missing extensions worth warning about
     */
    public static function missingRecommended(array $recommendedExts, bool $shopEnabled = true): array
    {
        $missing = [];
        foreach ($recommendedExts as $ext => $desc) {
            if (\in_array($ext, self::SKIPPED_DRIVER_EXTS, true)) {
                continue;
            }
            if ($ext === 'curl') {
                continue;
            }
            if ($ext === 'bcmath' && !$shopEnabled) {
                continue;
            }
            if (!\extension_loaded($ext)) {
                $missing[$ext] = $desc;
            }
        }

        return $missing;
    }
}
