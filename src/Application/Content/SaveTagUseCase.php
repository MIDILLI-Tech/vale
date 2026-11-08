<?php

declare(strict_types=1);

namespace ValeApp\Application\Content;

use ValeApp\Domain\Content\Entity\ContentType;
use ValeApp\Domain\Content\Entity\Tag;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Domain\Content\Repository\TagRepositoryInterface;
use ValeApp\Domain\Shared\Exception\ValidationException;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Infrastructure\Content\RedirectService;
use ValeApp\Infrastructure\Content\SlugService;

final class SaveTagUseCase
{
    public function __construct(
        private readonly TagRepositoryInterface $tags,
        private readonly SlugService $slugs,
        private readonly RedirectService $redirects,
        private readonly ?SettingsRepositoryInterface $settings = null,
        private readonly ?ContentTypeRepositoryInterface $contentTypes = null,
    ) {
    }

    /**
     * @param array{name: string, slug?: string, content_type?: string} $data
     */
    public function execute(array $data, ?int $id = null): Tag
    {
        if (empty($data['name'])) {
            throw ValidationException::withMessages(['name' => 'Name is required.']);
        }

        $contentType = !empty($data['content_type']) ? (string) $data['content_type'] : 'post';

        if ($id !== null) {
            $tag = $this->tags->findById($id);
            if ($tag === null) {
                throw ValidationException::withMessages(['id' => 'Tag not found.']);
            }
            $oldSlug = (string) $tag->getSlug();
            $tag->setName($data['name']);
            $slug = $this->slugs->unique(
                $data['slug'] ?? $data['name'],
                VL_DB_PREFIX . 'tags',
                'slug',
                $id,
                'content_type = ? AND language = ?',
                [$contentType, $tag->getLanguage()]
            );
            $tag->setSlug($slug);

            $oldUrl = $this->archiveUrlFor($contentType, $oldSlug);
            $newUrl = $this->archiveUrlFor($contentType, (string) $slug);
            if ($oldSlug !== (string) $slug) {
                $this->redirects->recordRedirect($oldUrl, $newUrl);
            }
        } else {
            $lang = $data['language'] ?? 'en';
            $slug = $this->slugs->unique(
                $data['slug'] ?? $data['name'],
                VL_DB_PREFIX . 'tags',
                'slug',
                null,
                'content_type = ? AND language = ?',
                [$contentType, $lang]
            );
            $tag  = new Tag($data['name'], $slug, $contentType);
            $tag->setLanguage($lang);

            $translationOf = isset($data['translation_of']) && $data['translation_of'] !== '' ? (int) $data['translation_of'] : null;
            if ($translationOf !== null) {
                $original = $this->tags->findById($translationOf);
                if ($original === null) {
                    throw ValidationException::withMessages(['translation_of' => 'Selected original not found.']);
                }
                if ($original->getLanguage() === $lang) {
                    throw ValidationException::withMessages(['translation_of' => 'Cannot translate into the same language.']);
                }
                foreach ($this->tags->findTranslations($translationOf) as $existing) {
                    if ($existing->getLanguage() === $lang) {
                        throw ValidationException::withMessages(['translation_of' => 'This original already has a translation in this language.']);
                    }
                }
                $tag->setTranslationOf($translationOf);
            } else {
                $tag->setTranslationOf(null);
            }
        }

        return $this->tags->save($tag);
    }

    private function archiveUrlFor(string $contentType, string $slug): string
    {
        $tagPrefix = $this->settings ? ('/' . ltrim($this->settings->get('routing_tag_prefix', '/tag'), '/')) : '/tag';
        if ($contentType === 'post') {
            $blogPrefix = $this->settings ? ('/' . ltrim($this->settings->get('routing_blog_prefix', '/blog'), '/')) : '/blog';
            return $blogPrefix . $tagPrefix . '/' . $slug;
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

        return $prefix . $tagPrefix . '/' . $slug;
    }
}
