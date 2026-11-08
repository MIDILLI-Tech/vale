<?php

declare(strict_types=1);

namespace ValeApp\Application\Auth;

use ValeApp\Infrastructure\Session\SessionManager;

/**
 * Use case: Log the current user out.
 */
final class LogoutUseCase
{
    public function __construct(
        private readonly SessionManager $session,
    ) {
    }

    public function execute(): void
    {
        $this->session->destroy();
    }
}
