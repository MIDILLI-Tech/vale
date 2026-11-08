<?php

declare(strict_types=1);

namespace ValeApp\Application\Content;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ValeApp\Domain\Content\Entity\ContentType;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Infrastructure\Content\ContentSummary;
use ValeApp\Infrastructure\Content\ShortcodeProcessor;

final class BlogFeedService
{
    private ?string $feedName         = null;
    private ?string $feedSlug         = null;
    private ?string $routePrefix      = null;
    private ?string $locale           = null;
    private bool $localeFilterEnabled = true;
    private ?string $category         = null;
    private ?string $tag              = null;

    public function __construct(
        private readonly PostRepositoryInterface $posts,
        private readonly MediaRepositoryInterface $media,
        private readonly string $siteUrl,
        private readonly string $blogName = 'Blog',
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?SettingsRepositoryInterface $settings = null,
        private readonly ?ShortcodeProcessor $shortcodes = null,
    ) {
    }

    public function setFeedName(string $feedName): self
    {
        $this->feedName = $feedName;
        return $this;
    }

    public function setFeedSlug(string $feedSlug): self
    {
        $this->feedSlug = $feedSlug;
        return $this;
    }

    public function setRoutePrefix(string $routePrefix): self
    {
        $this->routePrefix = $routePrefix;
        return $this;
    }

    public function setLocale(?string $locale): self
    {
        $this->locale = $locale !== null ? strtolower(trim($locale)) : null;
        return $this;
    }

    public function setLocaleFilterEnabled(bool $enabled): self
    {
        $this->localeFilterEnabled = $enabled;
        return $this;
    }

    public function setCategory(?string $category): self
    {
        $this->category = $category !== null ? trim($category) : null;
        return $this;
    }

    public function setTag(?string $tag): self
    {
        $this->tag = $tag !== null ? trim($tag) : null;
        return $this;
    }

    public function generateRss(): string
    {
        $locale = $this->resolveLocale();

        $doc               = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $rss = $doc->createElement('rss');
        $rss->setAttribute('version', '2.0');
        $rss->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:atom', 'http://www.w3.org/2005/Atom');
        $rss->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:content', 'http://purl.org/rss/1.0/modules/content/');
        $doc->appendChild($rss);

        $channel = $doc->createElement('channel');
        $rss->appendChild($channel);

        if ($this->feedSlug !== null) {
            $prefix = $this->routePrefix !== null
                ? '/' . ltrim($this->routePrefix, '/')
                : ContentType::buildPublicBasePath(null, (string) $this->feedSlug);
            $title       = $this->feedName . ' feed';
            $description = $this->feedName . ' — latest items';
        } else {
            $prefix      = '/' . ltrim($this->settings?->get('routing_blog_prefix', '/blog') ?? '/blog', '/');
            $title       = $this->blogName;
            $description = $this->blogName . ' — latest posts';
        }

        $feedPath = $prefix . '/feed.xml';
        $langTag  = $this->usesLocaleFilter() ? ' [' . strtoupper($locale) . ']' : '';

        $channel->appendChild($doc->createElement('title', $title . $langTag));
        $channel->appendChild($doc->createElement('link', $this->buildPublicUrl($prefix, $locale)));
        $channel->appendChild($doc->createElement('description', $description . $langTag));
        $channel->appendChild($doc->createElement('language', $locale));

        $selfLink = $doc->createElementNS('http://www.w3.org/2005/Atom', 'atom:link');
        $selfLink->setAttribute('href', $this->buildPublicUrl($feedPath, $locale));
        $selfLink->setAttribute('rel', 'self');
        $selfLink->setAttribute('type', 'application/rss+xml');
        $channel->appendChild($selfLink);

        if ($this->usesLocaleFilter()) {
            foreach ($this->getContentLanguages() as $langCode) {
                $alternate = $doc->createElementNS('http://www.w3.org/2005/Atom', 'atom:link');
                $alternate->setAttribute('href', $this->buildPublicUrl($feedPath, $langCode));
                $alternate->setAttribute('rel', 'alternate');
                $alternate->setAttribute('hreflang', $langCode);
                $alternate->setAttribute('type', 'application/rss+xml');
                $channel->appendChild($alternate);
            }
        }

        $contentTypeFilter = $this->feedSlug ?? 'post';
        $filters           = $this->buildPostFilters($contentTypeFilter, $locale);
        if ($this->category !== null && $this->category !== '') {
            $filters['category'] = $this->category;
        }
        if ($this->tag !== null && $this->tag !== '') {
            $filters['tag'] = $this->tag;
        }
        $page              = 1;
        $perPage           = 50;

        do {
            $posts = $this->posts->findAll(new Pagination($page, $perPage), $filters);

            foreach ($posts as $post) {
                if ($post->isRssExcluded()) {
                    continue;
                }

                $item = $doc->createElement('item');
                $item->appendChild($doc->createElement('title', $this->sanitize($post->getTitle())));

                $link = $this->buildPublicUrl($prefix . '/' . ltrim((string) $post->getSlug(), '/'), $locale);
                $item->appendChild($doc->createElement('link', $link));

                $guid = $doc->createElement('guid', $link);
                $guid->setAttribute('isPermaLink', 'true');
                $item->appendChild($guid);

                $descriptionContent = $post->getExcerpt() ?: (string) $post->getMetaDescription();
                if ($descriptionContent === '' && $this->teaserWords() > 0) {
                    $descriptionContent = ContentSummary::plainTeaser($post->getBody(), null, 300);
                }
                if ($descriptionContent !== '') {
                    $descriptionContent = $this->prepareFeedText($descriptionContent);
                }
                if ($descriptionContent !== '') {
                    $desc = $doc->createElement('description');
                    $desc->appendChild($doc->createCDATASection($this->sanitize($descriptionContent)));
                    $item->appendChild($desc);
                }

                $body = $post->getBody();
                if ($body !== null && $body !== '') {
                    $body = $this->prepareFeedText($body);
                }
                if ($body !== null && $body !== '') {
                    $content = $doc->createElementNS('http://purl.org/rss/1.0/modules/content/', 'content:encoded');
                    $content->appendChild($doc->createCDATASection($body));
                    $item->appendChild($content);
                }

                if ($post->getPublishedAt() !== null) {
                    $item->appendChild($doc->createElement('pubDate', $post->getPublishedAt()->format('r')));
                }

                if ($post->getFeaturedImageId() !== null) {
                    try {
                        $media = $this->media->findById($post->getFeaturedImageId());
                        if ($media !== null) {
                            $imgUrl = $media->getUrl();
                            if (!str_starts_with($imgUrl, 'http://') && !str_starts_with($imgUrl, 'https://')) {
                                $imgUrl = rtrim($this->siteUrl, '/') . '/' . ltrim($imgUrl, '/');
                            }
                            $enclosure = $doc->createElement('enclosure');
                            $enclosure->setAttribute('url', $imgUrl);
                            $enclosure->setAttribute('type', 'image/jpeg');
                            $item->appendChild($enclosure);
                        }
                    } catch (\Throwable) {
                    }
                }

                $channel->appendChild($item);
            }

            $page++;
        } while (count($posts) === $perPage);

        $this->resetState();

        return $doc->saveXML();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPostFilters(string $contentType, string $locale): array
    {
        $filters = [
            'status'       => 'published',
            'content_type' => $contentType,
        ];

        if ($this->usesLocaleFilter()) {
            $filters['language'] = $locale;
            if (($this->settings?->get('i18n_display_originals_fallback', '1') ?? '1') === '1') {
                $filters['language_fallback'] = true;
            }
        }

        return $filters;
    }

    private function usesLocaleFilter(): bool
    {
        return $this->isMultiLanguage() && $this->localeFilterEnabled;
    }

    private function resolveLocale(): string
    {
        $default = strtolower(trim((string) ($this->settings?->get('default_locale', 'en') ?? 'en')));

        if (!$this->usesLocaleFilter()) {
            return $default;
        }

        if ($this->locale !== null && $this->locale !== '') {
            return $this->locale;
        }

        return $default;
    }

    private function isMultiLanguage(): bool
    {
        return ($this->settings?->get('multi_language_content', '0') ?? '0') === '1';
    }

    /**
     * @return string[]
     */
    private function getContentLanguages(): array
    {
        $default = strtolower(trim((string) ($this->settings?->get('default_locale', 'en') ?? 'en')));
        if (!$this->isMultiLanguage()) {
            return [$default];
        }

        $codes = array_values(array_filter(array_map(
            static fn (string $code): string => strtolower(trim($code)),
            explode(',', (string) ($this->settings?->get('content_languages', '') ?? '')),
        )));

        return $codes !== [] ? $codes : [$default];
    }

    private function buildPublicUrl(string $path, string $locale): string
    {
        if (function_exists('site_url')) {
            return site_url($path, [], ['lang' => $locale]);
        }

        return rtrim($this->siteUrl, '/') . '/' . ltrim($path, '/');
    }

    private function resetState(): void
    {
        $this->feedName              = null;
        $this->feedSlug              = null;
        $this->routePrefix           = null;
        $this->locale                = null;
        $this->localeFilterEnabled   = true;
        $this->category              = null;
        $this->tag                   = null;
    }

    private function teaserWords(): int
    {
        return (int) ($this->settings?->get('listing_teaser_words', '55') ?? '55');
    }

    private function prepareFeedText(string $text): string
    {
        if ($this->shortcodes !== null) {
            $text = $this->shortcodes->stripForFeed($text);
        }

        return $this->sanitize($text);
    }

    private function sanitize(string $text): string
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
        return trim($text);
    }
}
