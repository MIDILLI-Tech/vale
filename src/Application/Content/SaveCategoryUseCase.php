<?php

declare(strict_types=1);

namespace ValeApp\Application\Content;

use ValeApp\Domain\Content\Entity\Category;
use ValeApp\Domain\Content\Entity\ContentType;
use ValeApp\Domain\Content\Repository\CategoryRepositoryInterface;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Domain\Shared\Exception\ValidationException;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Infrastructure\Content\RedirectService;
use ValeApp\Infrastructure\Content\SlugService;

final class SaveCategoryUseCase
{
    public function __construct(
        private readonly CategoryRepositoryInterface $categories,
        private readonly SlugService $slugs,
        private readonly RedirectService $redirects,
        private readonly ?SettingsRepositoryInterface $settings = null,
        private readonly ?ContentTypeRepositoryInterface $contentTypes = null,
    ) {
    }

    /**
     * @param array{name: string, slug?: string, description?: string, parent_id?: int|null, sort_order?: int, content_type?: string} $data
     */
    public function execute(array $data, ?int $id = null): Category
    {
        if (empty($data['name'])) {
            throw ValidationException::withMessages(['name' => 'Name is required.']);
        }

        $contentType = !empty($data['content_type']) ? (string) $data['content_type'] : 'post';

        if ($id !== null) {
            $cat = $this->categories->findById($id);
            if ($cat === null) {
                throw ValidationException::withMessages(['id' => 'Category not found.']);
            }
            $oldSlug = (string) $cat->getSlug();
            $cat->setName($data['name']);
            $slug = $this->slugs->unique(
                !empty($data['slug']) ? $data['slug'] : $data['name'],
                VL_DB_PREFIX . 'categories',
                'slug',
                $id,
                'content_type = ? AND language = ?',
                [$contentType, $cat->getLanguage()]
            );
            $cat->setSlug($slug);

            $oldUrl = $this->archiveUrlFor($contentType, $oldSlug);
            $newUrl = $this->archiveUrlFor($contentType, (string) $slug);
            if ($oldSlug !== (string) $slug) {
                $this->redirects->recordRedirect($oldUrl, $newUrl);
            }
        } else {
            $lang = $data['language'] ?? 'en';
            $slug = $this->slugs->unique(
                !empty($data['slug']) ? $data['slug'] : $data['name'],
                VL_DB_PREFIX . 'categories',
                'slug',
                null,
                'content_type = ? AND language = ?',
                [$contentType, $lang]
            );
            $cat  = new Category($data['name'], $slug, $contentType);
            $cat->setLanguage($lang);

            $translationOf = isset($data['translation_of']) && $data['translation_of'] !== '' ? (int) $data['translation_of'] : null;
            if ($translationOf !== null) {
                $original = $this->categories->findById($translationOf);
                if ($original === null) {
                    throw ValidationException::withMessages(['translation_of' => 'Selected original not found.']);
                }
                if ($original->getLanguage() === $lang) {
                    throw ValidationException::withMessages(['translation_of' => 'Cannot translate into the same language.']);
                }
                foreach ($this->categories->findTranslations($translationOf) as $existing) {
                    if ($existing->getLanguage() === $lang) {
                        throw ValidationException::withMessages(['translation_of' => 'This original already has a translation in this language.']);
                    }
                }
                $cat->setTranslationOf($translationOf);
            } else {
                $cat->setTranslationOf(null);
            }
        }

        $cat->setDescription($data['description'] ?? null);
        $parentId = isset($data['parent_id']) && $data['parent_id'] !== '' ? (int) $data['parent_id'] : null;
        $cat->setParentId($parentId);
        $cat->setSortOrder((int) ($data['sort_order'] ?? 0));

        return $this->categories->save($cat);
    }

    private function archiveUrlFor(string $contentType, string $slug): string
    {
        $categoryPrefix = $this->settings ? ('/' . ltrim($this->settings->get('routing_category_prefix', '/category'), '/')) : '/category';
        if ($contentType === 'post') {
            $blogPrefix = $this->settings ? ('/' . ltrim($this->settings->get('routing_blog_prefix', '/blog'), '/')) : '/blog';
            return $blogPrefix . $categoryPrefix . '/' . $slug;
        }

        $prefix = '';
        if ($this->contentTypes) {
            $type = $this->contentTypes->findBySlug(new Slug($contentType));
            if ($type) {
                $prefix = $type->getPublicBasePath();
            }
        }
        if (!$prefix) {
            $prefix = ContentType::buildPublicBasePath(null, $contentType);
        }

        return $prefix . $categoryPrefix . '/' . $slug;
    }
}
