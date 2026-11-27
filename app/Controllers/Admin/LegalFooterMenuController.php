<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ValeApp\Application\Menu\LegalFooterMenuService;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

final class LegalFooterMenuController
{
    public function __construct(
        private readonly LegalFooterMenuService $service,
        private readonly DbalSettingsRepository $settings,
        private readonly SessionManager $session,
        private readonly Translator $translator,
    ) {
    }

    public function apply(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $result = $this->service->apply($this->settings->getGroup('ecommerce'));

        if ($result['added'] === 0) {
            $this->session->flash('info', $this->translator->get('The Legal column already has every selected page.'));
        } else {
            $this->session->flash('success', $this->translator->get(':count legal page(s) added to the Legal column of the :menu menu.', [
                ':count' => (string) $result['added'],
                ':menu'  => $result['menu_name'],
            ]));
        }

        return $response
            ->withHeader('Location', admin_url('/settings?tab=ecommerce&subtab=legal'))
            ->withStatus(302);
    }
}
