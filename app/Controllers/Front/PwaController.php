<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Front;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use ValeApp\Application\Pwa\PwaSettings;
use ValeApp\Infrastructure\Pwa\PwaIconGenerator;

final class PwaController
{
    public function __construct(
        private readonly PwaSettings $pwa,
        private readonly PwaIconGenerator $icons,
        private readonly Twig $twig,
    ) {
    }

    public function manifest(Request $request, Response $response): Response
    {
        if (!$this->pwa->isEnabled()) {
            return $response->withStatus(404);
        }

        $scope = $this->scope();
        $icons = [];

        if ($this->pwa->hasRasterLogo() && $this->icons->supports($this->pwa->logo())) {
            foreach ([192, 512] as $size) {
                $icons[] = [
                    'src'     => $scope . 'pwa/icon-' . $size . '.png',
                    'sizes'   => $size . 'x' . $size,
                    'type'    => 'image/png',
                    'purpose' => 'any',
                ];
            }
            $icons[] = [
                'src'     => $scope . 'pwa/icon-512.png',
                'sizes'   => '512x512',
                'type'    => 'image/png',
                'purpose' => 'maskable',
            ];
        } else {
            $icons[] = [
                'src'     => $this->pwa->logoUrl(),
                'sizes'   => 'any',
                'type'    => $this->pwa->logoMimeType(),
                'purpose' => 'any',
            ];
        }

        $manifest = [
            'id'               => $scope,
            'name'             => $this->pwa->appName(),
            'short_name'       => $this->pwa->shortName(),
            'description'      => $this->pwa->description(),
            'start_url'        => $scope,
            'scope'            => $scope,
            'display'          => 'standalone',
            'background_color' => $this->pwa->backgroundColor(),
            'theme_color'      => $this->pwa->themeColor(),
            'icons'            => $icons,
        ];
        if ($manifest['description'] === '') {
            unset($manifest['description']);
        }

        $response->getBody()->write((string) json_encode(
            $manifest,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return $response
            ->withHeader('Content-Type', 'application/manifest+json; charset=UTF-8')
            ->withHeader('Cache-Control', 'no-cache, must-revalidate');
    }

    public function serviceWorker(Request $request, Response $response): Response
    {
        if (!$this->pwa->isEnabled()) {
            return $response->withStatus(404);
        }

        $source = base_path('resources/js/pwa-sw.js');
        if (!is_file($source)) {
            return $response->withStatus(404);
        }

        $scope   = $this->scope();
        $version = substr(sha1(implode('|', [
            (string) @filemtime($source),
            $this->pwa->appName(),
            $this->pwa->shortName(),
            $this->pwa->themeColor(),
            $this->pwa->backgroundColor(),
            $this->pwa->logo(),
        ])), 0, 12);

        $javascript = strtr((string) file_get_contents($source), [
            '__PWA_VERSION__'      => json_encode($version, JSON_THROW_ON_ERROR),
            '__PWA_BASE__'         => json_encode(rtrim(__app_base_path(), '/'), JSON_THROW_ON_ERROR),
            '__PWA_SCOPE__'        => json_encode($scope, JSON_THROW_ON_ERROR),
            '__PWA_OFFLINE_URL__'  => json_encode($scope . 'pwa/offline', JSON_THROW_ON_ERROR),
            '__PWA_MANIFEST_URL__' => json_encode($scope . 'manifest.webmanifest', JSON_THROW_ON_ERROR),
        ]);

        $response->getBody()->write($javascript);

        return $response
            ->withHeader('Content-Type', 'application/javascript; charset=UTF-8')
            ->withHeader('Cache-Control', 'no-cache, must-revalidate')
            ->withHeader('Service-Worker-Allowed', $scope);
    }

    /** @param array<string, string> $args */
    public function icon(Request $request, Response $response, array $args): Response
    {
        if (!$this->pwa->isEnabled()) {
            return $response->withStatus(404);
        }

        $size = (int) ($args['size'] ?? 0);
        $png  = $this->icons->render($this->pwa->logo(), $size, $this->pwa->backgroundColor());
        if ($png === null) {
            return $response->withStatus(404);
        }

        $response->getBody()->write($png);

        return $response
            ->withHeader('Content-Type', 'image/png')
            ->withHeader('Cache-Control', 'no-cache, must-revalidate');
    }

    public function offline(Request $request, Response $response): Response
    {
        if (!$this->pwa->isEnabled()) {
            return $response->withStatus(404);
        }

        return $this->twig->render($response, 'pwa/offline.twig', [
            'pwa_name'             => $this->pwa->appName(),
            'pwa_theme_color'      => $this->pwa->themeColor(),
            'pwa_background_color' => $this->pwa->backgroundColor(),
            'pwa_logo'             => $this->pwa->logoUrl(),
        ]);
    }

    private function scope(): string
    {
        $base = rtrim(__app_base_path(), '/');

        return ($base !== '' ? $base : '') . '/';
    }
}
