<?php

declare(strict_types=1);

namespace ValeApp\Application\Plugin;

use RuntimeException;

/**
 * Raised when a plugin manifest is missing, malformed, unsafe or incompatible.
 */
class InvalidPluginException extends RuntimeException
{
    /**
     * @param string       $message     Full diagnostic message (logs, CLI).
     * @param string|null  $userMessage Short message safe to show in the admin UI.
     */
    public function __construct(
        string $message,
        public readonly ?string $userMessage = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
