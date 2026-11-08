<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Front;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use ValeApp\Application\Plugin\PluginAssetResolver;
use ValeApp\Application\Plugin\PluginManager;

/**
 * GET /plugin-assets/{slug}/{path:.+}
 *
 * Serves content/plugins/<slug>/assets/<path> for enabled plugins. Code and
 * markup extensions are never served (see PluginAssetResolver); disabled
 * plugins and operator-disabled installs answer 404.
 */
final class PluginAssetController
{
    public function __construct(
        private readonly PluginManager $plugins,
        private readonly PluginAssetResolver $assets,
    ) {
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $slug = (string) ($args['slug'] ?? '');
        $path = (string) ($args['path'] ?? '');

        if (
            $this->plugins->isDisabledByOperator()
            || !$this->plugins->isGloballyEnabled()
            || !$this->plugins->isEnabled($slug)
        ) {
            return $this->notFound($response);
        }

        $asset = $this->assets->resolve($this->plugins->resolveDirectory($slug), $path);
        if ($asset === null) {
            return $this->notFound($response);
        }

        $contents = @file_get_contents($asset['path']);
        if ($contents === false) {
            return $this->notFound($response);
        }

        $response->getBody()->write($contents);

        return $response
            ->withHeader('Content-Type', $asset['content_type'])
            ->withHeader('Content-Length', (string) $asset['size'])
            ->withHeader('Cache-Control', 'public, max-age=3600')
            // Never let a browser sniff a served file into an executable type.
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    private function notFound(Response $response): Response
    {
        return $response->withStatus(404)->withHeader('Cache-Control', 'no-store');
    }
}
