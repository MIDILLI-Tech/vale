<?php

declare(strict_types=1);

namespace ValeApp\App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Response;
use ValeApp\Domain\Content\RoutingPrefixTypes;
use ValeApp\Infrastructure\Content\RedirectService;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Translation\Translator;

class RedirectMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RedirectService $redirects,
        private readonly Translator $translator,
        private readonly DbalSettingsRepository $settings
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $response = $handler->handle($request);
        } catch (HttpNotFoundException $e) {
            $response = new Response(404);
        }

        // If the response is a 404, check if we have a redirect for this path
        if ($response->getStatusCode() === 404) {
            $path = $request->getUri()->getPath();

            // Normalize path by removing base path if present. The match must
            // end on a segment boundary: with a '/vale' install, '/vale-old-page'
            // is a page of its own, not '/old-page' behind the base path.
            $basePath = __app_base_path();
            if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
                $path = substr($path, strlen($basePath));
            }

            if ($path === '') {
                $path = '/';
            }

            $originalPath   = $path;
            $detectedLocale = null;

            // If multi-language content is enabled, check if the path starts with a locale
            if ($this->settings->get('multi_language_content', '0') === '1') {
                $supported = $this->translator->getSupportedLocales();
                $segments  = explode('/', ltrim($path, '/'));
                $first     = $segments[0] ?? '';
                if ($first !== '' && in_array($first, $supported, true)) {
                    $detectedLocale = $first;
                    $path           = '/' . implode('/', array_slice($segments, 1));
                    if ($path === '') {
                        $path = '/';
                    }
                }
            }

            $newPath = $this->redirects->findRedirect($path);

            // If not found with stripped path, try with original path
            if ($newPath === null && $originalPath !== $path) {
                $newPath = $this->redirects->findRedirect($originalPath);
            }

            // Cross-language prefix recovery
            // Example: Active locale is 'en' but user accessed '/hesap/profile' (TR prefix)
            // It should redirect to '/account/profile'
            if ($newPath === null) {
                $prefixTypes = RoutingPrefixTypes::ALL_TYPES;
                $supported   = $this->translator->getSupportedLocales();

                foreach ($prefixTypes as $type) {
                    $globalPrefixKey = 'routing_' . $type . '_prefix';
                    $globalPrefix    = $this->settings->get($globalPrefixKey, ($type === 'page' ? '' : '/' . $type));
                    $globalPrefix    = '/' . ltrim((string) $globalPrefix, '/');

                    foreach ($supported as $lang) {
                        $localPrefixKey = $globalPrefixKey . '_' . $lang;
                        $localPrefix    = $this->settings->get($localPrefixKey);

                        if ($localPrefix !== null && $localPrefix !== '' && $localPrefix !== '/') {
                            $localPrefix = '/' . ltrim($localPrefix, '/');

                            if ($path === $localPrefix || str_starts_with($path, $localPrefix . '/')) {
                                $suffix  = substr($path, strlen($localPrefix));
                                // Convert to global prefix, then check for an actual redirect
                                $globalPath     = $globalPrefix . $suffix;
                                $foundRedirect  = $this->redirects->findRedirect($globalPath);
                                if ($foundRedirect !== null) {
                                    $newPath = $foundRedirect;
                                    break 2;
                                }
                            }
                        }
                    }
                }
            }

            if ($newPath !== null) {
                $redirectResponse = new Response(301);
                // Maintain the detected locale context if the redirect was found via stripped path
                $targetUrl = ($detectedLocale && !str_starts_with($newPath, 'http'))
                    ? site_url($newPath, [], ['lang' => $detectedLocale])
                    : site_url($newPath);

                // Query strings (utm_*, page, filters) must survive the hop,
                // otherwise campaign and analytics data is silently dropped.
                if (!str_starts_with($newPath, 'http')) {
                    $query = $request->getUri()->getQuery();
                    if ($query !== '' && !str_contains($targetUrl, '?')) {
                        $targetUrl .= '?' . $query;
                    }
                }

                return $redirectResponse->withHeader('Location', $targetUrl);
            }
        }

        return $response;
    }
}
