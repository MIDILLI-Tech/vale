<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use ValeApp\Application\Menu\SaveMenuUseCase;
use ValeApp\Domain\Content\Repository\CategoryRepositoryInterface;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Menu\Entity\Menu;
use ValeApp\Domain\Menu\Entity\MenuItem;
use ValeApp\Domain\Menu\Repository\MenuRepositoryInterface;
use ValeApp\Domain\Shared\Exception\ValidationException;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Repository\ProductCategoryRepositoryInterface;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Infrastructure\Cache\PageCache;
use ValeApp\Infrastructure\Content\SlugService;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Theme\ThemeManager;
use ValeApp\Infrastructure\Translation\Translator;

final class MenuController
{
    public function __construct(
        private readonly Translator $translator,
        private readonly Twig $twig,
        private readonly MenuRepositoryInterface $menus,
        private readonly SaveMenuUseCase $saveMenu,
        private readonly SessionManager $session,
        private readonly PostRepositoryInterface $posts,
        private readonly CategoryRepositoryInterface $categories,
        private readonly ProductRepositoryInterface $products,
        private readonly ProductCategoryRepositoryInterface $productCategories,
        private readonly SlugService $slugs,
        private readonly ThemeManager $themes,
        private readonly ContentTypeRepositoryInterface $contentTypes,
        private readonly DbalSettingsRepository $settings,
        private readonly ?PageCache $pageCache = null,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $cptEnabled = $this->settings->get('custom_content_types_enabled', '1') === '1';

        $allMenus = $this->menus->findAll();

        return $this->twig->render($response, 'admin/menus/index.twig', [
            'menus'                => $allMenus,
            'all_menus'            => $allMenus,
            'all_menus_json'       => array_map(fn (Menu $m) => [
                'id'            => $m->getId(),
                'name'          => $m->getName(),
                'language'      => $m->getLanguage(),
                'translationOf' => $m->getTranslationOf(),
            ], $allMenus),
            'defined_menus'        => $this->themes->getDefinedMenus(),
            'custom_content_types' => $cptEnabled ? $this->contentTypes->findActiveCustom() : [],
            'flash_success'        => $this->session->flash('flash_success'),
            'flash_error'          => $this->session->flash('flash_error'),
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $body  = (array) $request->getParsedBody();
        $items = $this->parseItems($body);

        try {
            $this->saveMenu->execute($body, $items);
            $this->flushPageCache();
            $this->session->set('flash_success', $this->translator->get('Menu created successfully.'));
            return $response->withHeader('Location', admin_url('/menus'))->withStatus(302);
        } catch (ValidationException $e) {
            $errors = implode('; ', array_map(fn ($m) => is_array($m) ? implode(', ', $m) : (string) $m, $e->getErrors()));
            $this->session->set('flash_error', $this->translator->get($errors));
            return $response->withHeader('Location', admin_url('/menus'))->withStatus(302);
        }
    }

    public function getMenuJson(Request $request, Response $response, array $args): Response
    {
        $menu = $this->menus->findById((int) $args['id']);
        if ($menu === null) {
            return $response->withStatus(404);
        }

        $items           = $this->menus->findItemsByMenuId($menu->getId());
        $serializedItems = [];
        foreach ($items as $item) {
            $serializedItems[] = [
                'id'          => $item->getId(),
                'parentId'    => $item->getParentId(),
                'label'       => $item->getLabel(),
                'type'        => $item->getType(),
                'url'         => $item->getUrl(),
                'target'      => $item->getTarget(),
                'referenceId' => $item->getReferenceId(),
                'sortOrder'   => $item->getSortOrder(),
                'cssClass'    => $item->getCssClass(),
            ];
        }

        $data = [
            'menu' => [
                'id'            => $menu->getId(),
                'name'          => $menu->getName(),
                'location'      => $menu->getLocation(),
                'language'      => $menu->getLanguage(),
                'translationOf' => $menu->getTranslationOf(),
            ],
            'items' => $serializedItems,
        ];

        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id    = (int) $args['id'];
        $body  = (array) $request->getParsedBody();
        $items = $this->parseItems($body);

        try {
            $this->saveMenu->execute($body, $items, $id);
            $this->flushPageCache();
            $this->session->set('flash_success', $this->translator->get('Menu updated successfully.'));
            return $response->withHeader('Location', admin_url('/menus'))->withStatus(302);
        } catch (ValidationException $e) {
            $errors = implode('; ', array_map(fn ($m) => is_array($m) ? implode(', ', $m) : (string) $m, $e->getErrors()));
            $this->session->set('flash_error', $this->translator->get($errors));
            return $response->withHeader('Location', admin_url('/menus'))->withStatus(302);
        }
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $this->menus->delete((int) $args['id']);
        $this->flushPageCache();
        $this->session->set('flash_success', $this->translator->get('Menu deleted.'));
        return $response->withHeader('Location', admin_url('/menus'))->withStatus(302);
    }

    public function cloneMenu(Request $request, Response $response, array $args): Response
    {
        $original = $this->menus->findById((int) $args['id']);
        if ($original === null) {
            return $response->withStatus(404);
        }

        $name = $original->getName() . ' (Copy)';
        $slug = $this->slugs->unique($name, VL_DB_PREFIX . 'menus');

        $clone = new Menu($name, $slug);
        $clone->setLocation($original->getLocation());
        $newId = $this->menus->save($clone);

        $items = $this->menus->findItemsByMenuId($original->getId());
        if (!empty($items)) {
            $oldToNew    = [];
            $clonedItems = [];

            foreach ($items as $item) {
                $mi = new MenuItem($newId, $item->getLabel());
                $mi->setType($item->getType());
                $mi->setUrl($item->getUrl());
                $mi->setTarget($item->getTarget());
                $mi->setReferenceId($item->getReferenceId());
                $mi->setSortOrder($item->getSortOrder());
                $mi->setCssClass($item->getCssClass());
                $this->menus->saveItem($mi);
                $oldToNew[$item->getId()] = $mi->getId();
                $clonedItems[]            = $mi;
            }

            foreach ($clonedItems as $i => $mi) {
                $oldParentId = $items[$i]->getParentId();
                if ($oldParentId !== null && isset($oldToNew[$oldParentId])) {
                    $mi->setParentId($oldToNew[$oldParentId]);
                    $this->menus->saveItem($mi);
                }
            }
        }

        $this->flushPageCache();
        $this->session->set('flash_success', 'Menu cloned successfully.');
        return $response->withHeader('Location', admin_url('/menus'))->withStatus(302);
    }

    /**
     * AJAX: return JSON list of content items for the menu item picker.
     * GET /admin/menus/content-items?type=post|page|product|category|cpt_slug&q=search
     */
    public function contentItems(Request $request, Response $response): Response
    {
        $type  = (string) ($request->getQueryParams()['type'] ?? '');
        $q     = (string) ($request->getQueryParams()['q'] ?? '');
        $items = [];

        $filters = ['status' => ['published', 'unlisted']];
        if ($q !== '') {
            $filters['search'] = $q;
        }

        $limit = $q !== '' ? 20 : 50;

        switch ($type) {
            case 'post':
                $blogPrefix              = '/' . ltrim($this->settings->get('routing_blog_prefix', '/blog'), '/');
                $filters['content_type'] = 'post';
                foreach ($this->posts->findAll(new Pagination(1, $limit, 'title', 'asc'), $filters) as $p) {
                    $title = trim((string) $p->getTitle());
                    if ($title === '') {
                        continue;
                    }
                    $items[] = ['id' => $p->getId(), 'label' => $title, 'url' => site_url($blogPrefix . '/') . $p->getSlug()];
                }
                break;
            case 'page':
                $pagePrefix              = $this->settings->get('routing_page_prefix', '');
                $pagePrefix              = $pagePrefix ? ('/' . ltrim($pagePrefix, '/')) : '';
                $filters['content_type'] = 'page';
                foreach ($this->posts->findAll(new Pagination(1, $limit, 'title', 'asc'), $filters) as $p) {
                    $title = trim((string) $p->getTitle());
                    if ($title === '') {
                        continue;
                    }
                    $items[] = ['id' => $p->getId(), 'label' => $title, 'url' => site_url($pagePrefix . '/') . $this->posts->buildPagePath($p)];
                }
                break;
            case 'product':
                $shopPrefix = '/' . ltrim($this->settings->get('routing_shop_prefix', '/shop'), '/');
                foreach ($this->products->findAll(new Pagination(1, $limit, 'name', 'asc'), $filters) as $p) {
                    $name = trim((string) $p->getName());
                    if ($name === '') {
                        continue;
                    }
                    $items[] = ['id' => $p->getId(), 'label' => $name, 'url' => site_url($shopPrefix . '/') . $p->getSlug()];
                }
                break;
            case 'shop_category':
                if (($this->settings->get('shop_enabled', '1') ?? '1') !== '1') {
                    break;
                }
                $shopPrefix = '/' . ltrim($this->settings->get('routing_shop_prefix', '/shop'), '/');
                $all        = $this->productCategories->findAll();
                $all        = array_values(array_filter(
                    $all,
                    fn ($c) => trim((string) $c->getName()) !== ''
                ));
                if ($q !== '') {
                    $qLower = mb_strtolower($q);
                    $all    = array_filter(
                        $all,
                        fn ($c) => mb_stripos($c->getName(), $qLower)      !== false
                            || mb_stripos((string) $c->getSlug(), $qLower) !== false
                    );
                }
                $all = array_slice($all, 0, $limit);
                foreach ($all as $c) {
                    $items[] = [
                        'id'    => $c->getId(),
                        'label' => $c->getName(),
                        'url'   => site_url($shopPrefix, [], ['category' => (string) $c->getSlug()]),
                    ];
                }
                break;
            case 'category':
                $categoryPrefix = '/' . ltrim($this->settings->get('routing_category_prefix', '/category'), '/');
                $all            = $this->categories->findAll();
                // Skip untitled leftovers (e.g. from WP XML imports) — title is required.
                $all = array_values(array_filter($all, fn ($c) => trim((string) $c->getName()) !== ''));
                if ($q !== '') {
                    $qLower = mb_strtolower($q);
                    $all    = array_filter($all, fn ($c) => mb_stripos($c->getName(), $qLower) !== false
                        || mb_stripos((string) $c->getSlug(), $qLower)                         !== false);
                    $all = array_slice($all, 0, $limit);
                } else {
                    $all = array_slice($all, 0, $limit);
                }
                foreach ($all as $c) {
                    $items[] = ['id' => $c->getId(), 'label' => $c->getName(), 'url' => site_url($categoryPrefix . '/' . $c->getSlug())];
                }
                break;
            default:
                // Custom content type (CPT) — type is the slug
                $filters['content_type'] = $type;
                foreach ($this->posts->findAll(new Pagination(1, $limit, 'title', 'asc'), $filters) as $p) {
                    $title = trim((string) $p->getTitle());
                    if ($title === '') {
                        continue;
                    }
                    $items[] = ['id' => $p->getId(), 'label' => $title, 'url' => site_url('/' . $type . '/') . $p->getSlug()];
                }
                break;
        }

        $response->getBody()->write(json_encode($items, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Menus render in the header/footer of every page, so any change makes all
     * cached HTML stale — a full flush is the only correct invalidation here.
     */
    private function flushPageCache(): void
    {
        $this->pageCache?->flush();
    }

    /**
     * @return array<int, array{label: string, type?: string, url?: string, target?: string, reference_id?: int|null, sort_order?: int, css_class?: string}>
     */
    private function parseItems(array $body): array
    {
        $items         = [];
        $labels        = (array) ($body['item_label'] ?? []);
        $types         = (array) ($body['item_type'] ?? []);
        $urls          = (array) ($body['item_url'] ?? []);
        $targets       = (array) ($body['item_target'] ?? []);
        $classes       = (array) ($body['item_css_class'] ?? []);
        $parentIndexes = (array) ($body['item_parent_index'] ?? []);
        $referenceIds  = (array) ($body['item_reference_id'] ?? []);

        foreach ($labels as $i => $label) {
            $label = trim($label);
            if ($label === '') {
                continue;
            }
            $items[] = [
                'label'        => $label,
                'type'         => $types[$i]   ?? 'custom',
                'url'          => $urls[$i]    ?? null,
                'target'       => $targets[$i] ?? '_self',
                'reference_id' => isset($referenceIds[$i]) && $referenceIds[$i] !== '' ? (int) $referenceIds[$i] : null,
                'sort_order'   => $i,
                'css_class'    => $classes[$i] ?? null,
                'parent_index' => isset($parentIndexes[$i]) ? (int) $parentIndexes[$i] : -1,
            ];
        }

        return $items;
    }
}
