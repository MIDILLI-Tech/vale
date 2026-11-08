<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Front;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use ValeApp\Application\Seo\AiRobotsTxtUseCase;
use ValeApp\Application\Seo\GenerateSitemapUseCase;
use ValeApp\Application\Seo\RobotsTxtUseCase;

final class SeoController
{
    public function __construct(
        private readonly GenerateSitemapUseCase $sitemap,
        private readonly RobotsTxtUseCase $robots,
        private readonly AiRobotsTxtUseCase $aiRobots,
    ) {
    }

    public function sitemap(Request $request, Response $response): Response
    {
        $xml = $this->sitemap->execute();
        $response->getBody()->write($xml);
        return $response->withHeader('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function robots(Request $request, Response $response): Response
    {
        $txt = $this->robots->execute();
        $response->getBody()->write($txt);
        return $response->withHeader('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function aiRobots(Request $request, Response $response): Response
    {
        $txt = $this->aiRobots->execute();
        $response->getBody()->write($txt);
        return $response->withHeader('Content-Type', 'text/plain; charset=UTF-8');
    }
}
