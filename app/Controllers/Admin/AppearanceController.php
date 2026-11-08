<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

final class AppearanceController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly DbalSettingsRepository $settings,
        private readonly SessionManager $session,
        private readonly Translator $translator,
    ) {
    }

    /**
     * GET /admin/appearance/fonts — show font selection page.
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $currentFont = $this->settings->get('font_family', 'inter');

        $fonts = [
            [
                'id'          => 'inter',
                'name'        => 'Inter',
                'description' => 'Modern, geometric sans-serif designed for screens.',
                'class'       => 'font-sans',
            ],
            [
                'id'          => 'roboto',
                'name'        => 'Roboto',
                'description' => 'Mechanical skeleton and the forms are largely geometric.',
                'class'       => 'font-roboto',
            ],
            [
                'id'          => 'open-sans',
                'name'        => 'Open Sans',
                'description' => 'Friendly appearance and excellent legibility.',
                'class'       => 'font-open-sans',
            ],
            [
                'id'          => 'montserrat',
                'name'        => 'Montserrat',
                'description' => 'Geometric sans-serif inspired by urban typography.',
                'class'       => 'font-montserrat',
            ],
            [
                'id'          => 'playfair-display',
                'name'        => 'Playfair Display',
                'description' => 'Elegant high-contrast serif, ideal for display headings.',
                'class'       => 'font-playfair',
            ],
        ];

        return $this->twig->render($response, 'admin/appearance/fonts.twig', [
            'fonts'        => $fonts,
            'current_font' => $currentFont,
        ]);
    }

    /**
     * POST /admin/appearance/fonts — update the selected font.
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $font = trim((string) ($body['font'] ?? 'inter'));

        $allowedFonts = ['inter', 'roboto', 'open-sans', 'montserrat', 'playfair-display'];
        if (!in_array($font, $allowedFonts, true)) {
            $font = 'inter';
        }

        $this->settings->set('font_family', $font, 'general');

        $this->session->set('flash_success', $this->translator->get('Font settings updated.'));

        return $response->withHeader('Location', admin_url('/appearance/fonts'))->withStatus(302);
    }
}
