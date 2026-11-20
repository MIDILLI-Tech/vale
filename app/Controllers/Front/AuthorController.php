<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Front;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\User\Repository\UserRepositoryInterface;
use ValeApp\Infrastructure\Content\MiniMarkdown;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;

final class AuthorController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly PostRepositoryInterface $posts,
        private readonly UserRepositoryInterface $users,
        private readonly DbalSettingsRepository $settings,
    ) {
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $author = $this->users->findById((int) ($args['id'] ?? 0));

        if ($author === null || !$author->isActive()) {
            return $this->twig->render($response->withStatus(404), 'errors/404.twig');
        }

        $perPage = max(1, min(50, (int) $this->settings->get('posts_per_page', '10')));
        $page    = max(1, (int) ($request->getQueryParams()['page'] ?? 1));

        $filters = [
            'content_type' => 'post',
            'status'       => 'published',
            'author_id'    => (int) $author->getId(),
        ];

        $posts = $this->posts->findAll(new Pagination($page, $perPage), $filters);
        $total = $this->posts->count($filters);

        $profilesEnabled = $this->settings->get('user_profiles_enabled', '0') === '1';
        $bio             = $profilesEnabled ? trim((string) $author->getBio()) : '';

        return $this->twig->render($response, 'author.twig', [
            'author'      => $author,
            'public_name' => $author->getPublicName(),
            'bio'         => $bio,
            'bio_html'    => MiniMarkdown::render($bio),
            'posts'       => $posts,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ]);
    }
}
