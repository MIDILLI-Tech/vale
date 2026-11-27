<?php

declare(strict_types=1);

namespace ValeApp\Application\Menu;

use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Menu\Entity\Menu;
use ValeApp\Domain\Menu\Entity\MenuItem;
use ValeApp\Domain\Menu\Repository\MenuRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;

/**
 * Builds the optional "Legal" column in the footer menu from the pages chosen
 * under E-Commerce → Legal & Compliance. Creating the menu is always an explicit
 * admin action; this service never runs on save.
 */
final class LegalFooterMenuService
{
    public const MANAGED_CLASS = 'vl-legal-managed';

    /** @var array<string, string> setting key => menu label */
    private const PAGES = [
        'policy_page_imprint' => 'Imprint',
        'policy_page_privacy' => 'Privacy Policy',
        'policy_page_terms'   => 'Terms & Conditions',
        'policy_page_returns' => 'Return Policy',
    ];

    public function __construct(
        private readonly MenuRepositoryInterface $menus,
        private readonly PostRepositoryInterface $posts,
    ) {
    }

    /**
     * @return array{menu_id: ?int, menu_name: string, has_heading: bool, links: int}
     */
    public function status(): array
    {
        $menu = $this->menus->findByLocation('footer');
        if ($menu === null) {
            return ['menu_id' => null, 'menu_name' => '', 'has_heading' => false, 'links' => 0];
        }

        $items   = $this->menus->findItemsByMenuId((int) $menu->getId());
        $heading = $this->findHeading($items);
        $links   = $heading === null ? 0 : count(array_filter(
            $items,
            static fn (MenuItem $item): bool => $item->getParentId() === $heading->getId(),
        ));

        return [
            'menu_id'     => (int) $menu->getId(),
            'menu_name'   => $menu->getName(),
            'has_heading' => $heading !== null,
            'links'       => $links,
        ];
    }

    /**
     * Creates the footer menu when missing and adds every selected legal page
     * that is not in the Legal column yet. Existing items are never removed.
     *
     * @param array<string, mixed> $settings
     * @return array{menu_id: int, menu_name: string, created: bool, added: int}
     */
    public function apply(array $settings): array
    {
        $menu    = $this->menus->findByLocation('footer');
        $created = false;

        if ($menu === null) {
            $menu = new Menu(__('Footer'), new Slug('footer'));
            $menu->setLocation('footer');
            $menu->setId($this->menus->save($menu));
            $created = true;
        }

        $items   = $this->menus->findItemsByMenuId((int) $menu->getId());
        $heading = $this->findHeading($items);

        if ($heading === null) {
            $heading = new MenuItem((int) $menu->getId(), __('Legal'));
            $heading->setType('custom');
            $heading->setUrl(null);
            $heading->setCssClass(self::MANAGED_CLASS);
            $heading->setSortOrder($this->nextSortOrder($items));
            $heading->setId($this->menus->saveItem($heading));
            $items[] = $heading;
        }

        $existing = [];
        foreach ($items as $item) {
            if ($item->getParentId() === $heading->getId() && $item->getReferenceId() !== null) {
                $existing[$item->getReferenceId()] = true;
            }
        }

        $added = 0;
        foreach (self::PAGES as $settingKey => $label) {
            $page = $this->findPublishedPage((string) ($settings[$settingKey] ?? ''));
            if ($page === null || isset($existing[$page->getId()])) {
                continue;
            }

            $item = new MenuItem((int) $menu->getId(), __($label));
            $item->setType('page');
            $item->setReferenceId($page->getId());
            $item->setUrl('/' . ltrim($this->posts->buildPagePath($page), '/'));
            $item->setParentId($heading->getId());
            $item->setCssClass(self::MANAGED_CLASS);
            $item->setSortOrder($this->nextSortOrder($items));
            $item->setId($this->menus->saveItem($item));
            $items[] = $item;
            $added++;
        }

        return [
            'menu_id'   => (int) $menu->getId(),
            'menu_name' => $menu->getName(),
            'created'   => $created,
            'added'     => $added,
        ];
    }

    /** @param MenuItem[] $items */
    private function findHeading(array $items): ?MenuItem
    {
        foreach ($items as $item) {
            if ($item->getParentId() === null && $item->getCssClass() === self::MANAGED_CLASS) {
                return $item;
            }
        }

        return null;
    }

    /** @param MenuItem[] $items */
    private function nextSortOrder(array $items): int
    {
        $max = 0;
        foreach ($items as $item) {
            $max = max($max, $item->getSortOrder());
        }

        return $max + 1;
    }

    private function findPublishedPage(string $slug): ?\ValeApp\Domain\Content\Entity\Post
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }

        try {
            $page = $this->posts->findBySlug(new Slug($slug), 'page');
        } catch (\Throwable) {
            return null;
        }

        return $page !== null && $page->isPublished() ? $page : null;
    }
}
