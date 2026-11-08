<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Front;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use ValeApp\Application\Seo\SeoState;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Seo\Repository\SeoMetaRepositoryInterface;
use ValeApp\Domain\Shared\Exception\DomainException;
use ValeApp\Domain\Shared\ValueObject\Slug;

final class PageController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly PostRepositoryInterface $posts,
        private readonly SeoMetaRepositoryInterface $seoMeta,
        private readonly SeoState $seoState,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $slug = new Slug($args['slug']);
        } catch (DomainException) {
            return $this->twig->render($response->withStatus(404), 'errors/404.twig');
        }

        $page = $this->posts->findBySlug($slug, 'page');

        if ($page === null || !in_array($page->getStatus(), ['published', 'unlisted'], true)) {
            return $this->twig->render($response->withStatus(404), 'errors/404.twig');
        }

        $seo = $this->seoMeta->findByEntity('page', $page->getId());
        $this->seoState->set($seo, $page);

        return $this->twig->render($response, 'page.twig', [
            'page' => $page,
            'seo'  => $seo,
        ]);
    }
}
