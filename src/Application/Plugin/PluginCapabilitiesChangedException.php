<?php

declare(strict_types=1);

namespace ValeApp\Application\Plugin;

/**
 * Raised when a release claims capabilities the installed version does not.
 *
 * Nothing is replaced: the update waits for an explicit approval of this exact
 * version, so a release can never widen its own access silently.
 */
final class PluginCapabilitiesChangedException extends InvalidPluginException
{
    /**
     * @param list<string> $capabilities capabilities the installed version does not declare
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $version,
        public readonly array $capabilities,
    ) {
        parent::__construct(sprintf(
            'Plugin "%s" v%s requests capabilities the installed version does not declare: %s.',
            $slug,
            $version,
            implode(', ', $capabilities),
        ));
    }
}
