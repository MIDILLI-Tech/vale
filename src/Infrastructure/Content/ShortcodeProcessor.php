<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Content;

use ValeApp\Application\Form\FormAccessService;
use ValeApp\Application\Form\FormCaptchaService;
use ValeApp\Application\Shop\VariationPrice;
use ValeApp\Domain\Content\Entity\Post;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Form\Entity\Form;
use ValeApp\Domain\Form\Repository\FormRepositoryInterface;
use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
use ValeApp\Domain\Shared\Exception\Redirect302Exception;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Security\CsrfManager;
use ValeApp\Infrastructure\Session\SessionManager;

final class ShortcodeProcessor
{
    private bool $hadShortcodes    = false;
    private bool $lightboxRendered = false;
    private bool $redirectRendered = false;

    private ?Post $currentPost = null;

    /** @var array<string, array<string, string>>|null Cached media_embeds catalog from reference-data.php */
    private ?array $mediaEmbedsCatalog = null;

    public function __construct(
        private readonly FormRepositoryInterface $forms,
        private readonly CsrfManager $csrf,
        private readonly SessionManager $session,
        private readonly PostRepositoryInterface $posts,
        private readonly MediaRepositoryInterface $media,
        private readonly ProductRepositoryInterface $products,
        private readonly DbalSettingsRepository $settings,
        private readonly FormAccessService $formAccess,
        private readonly FormCaptchaService $formCaptcha,
        private readonly string $basePath = '',
        private readonly ?ContentTypeRepositoryInterface $contentTypes = null,
    ) {
    }

    public function hadShortcodes(): bool
    {
        return $this->hadShortcodes;
    }

    public function process(string $content, ?Post $currentPost = null): string
    {
        $this->hadShortcodes    = false;
        $this->lightboxRendered = false;
        $this->redirectRendered = false;
        $this->currentPost      = $currentPost;

        // Tiptap stores " as &quot; — decode so shortcode regex can match
        // [form slug="xxx"]  — also strip wrapping <p> tag if present
        $content = preg_replace_callback(
            '/<p>\s*\[form\s+slug=(?:["\']|&quot;)([a-z0-9_-]+)(?:["\']|&quot;)\s*\]\s*<\/p>/',
            fn ($m) => $this->renderForm($m[1]),
            $content,
        );
        // Fallback: shortcode not wrapped in <p>
        $content = preg_replace_callback(
            '/\[form\s+slug=(?:["\']|&quot;)([a-z0-9_-]+)(?:["\']|&quot;)\s*\]/',
            fn ($m) => $this->renderForm($m[1]),
            $content,
        );

        // [newsletter_form] — also strip wrapping <p> tag
        $content = preg_replace_callback(
            '/<p>\s*\[newsletter_form(?:\s+([^\]]*))?\]\s*<\/p>/',
            fn ($m) => $this->renderNewsletterForm($this->decodeAttrs($m[1] ?? '')),
            $content,
        );
        $content = preg_replace_callback(
            '/\[newsletter_form(?:\s+([^\]]*))?\]/',
            fn ($m) => $this->renderNewsletterForm($this->decodeAttrs($m[1] ?? '')),
            $content,
        );

        // [toc] or [toc max_level="N"] — Table of Contents
        if (str_contains($content, '[toc]') || str_contains($content, '[toc ')) {
            $content = $this->processToc($content);
        }

        // [pages] or [pages max_level="N"] or [pages parent="slug"] — Child page links
        if (str_contains($content, '[pages]') || str_contains($content, '[pages ')) {
            $content = $this->processPages($content);
        }

        // [field name="..."] — Meta fields
        if (str_contains($content, '[field ')) {
            $content = $this->processMetaFields($content);
        }

        // {{ fieldname }} — Bare meta-field placeholders (custom content type fields)
        if ($this->currentPost !== null && str_contains($content, '{{')) {
            $content = $this->processBareMetaFields($content);
        }

        // [ad] — Advertisement
        if (stripos($content, '[ad]') !== false) {
            $content = $this->processAdShortcode($content);
        }

        // [carousel] — Image Carousel
        if (str_contains($content, '[carousel]') || str_contains($content, '[carousel ')) {
            $content = $this->processCarousel($content);
        }

        // Inline editor images that opted into the lightbox — render the shared
        // overlay once so clicking them opens the enlarged view.
        if (str_contains($content, 'data-lightbox="true"')) {
            $content .= $this->renderLightboxOverlay();
        }

        // [product] — Product Embed
        if (str_contains($content, '[product')) {
            $content = $this->processProduct($content);
        }

        // [link_button_group] — Link Button Group
        if (str_contains($content, '[link_button_group')) {
            $content = $this->processLinkButtonGroup($content);
        }

        // [link_button] — Link Button (legacy single buttons)
        if (str_contains($content, '[link_button]') || str_contains($content, '[link_button ')) {
            $content = $this->processLinkButton($content);
        }

        // [card_group] — Card Grid
        if (str_contains($content, '[card_group')) {
            $content = $this->processCardGroup($content);
        }

        // [hero] — Hero section (split / CTA)
        if (str_contains($content, '[hero]') || str_contains($content, '[hero ')) {
            $content = $this->processHero($content);
        }

        // [redirect] — Timed redirect to another URL
        if (str_contains($content, '[redirect]') || str_contains($content, '[redirect ')) {
            $content = $this->processRedirect($content);
        }

        // [embed]URL[/embed] — WordPress-style external video embed
        if (str_contains($content, '[embed]')) {
            $content = preg_replace_callback(
                '/<p>\s*\[embed\]\s*(.*?)\s*\[\/embed\]\s*<\/p>/is',
                fn ($m) => $this->renderEmbed($m[1]),
                $content,
            );
            $content = preg_replace_callback(
                '/\[embed\]\s*(.*?)\s*\[\/embed\]/is',
                fn ($m) => $this->renderEmbed($m[1]),
                $content,
            );
        }

        // Hero shortcodes create lightbox-marked images after the initial pass.
        if (str_contains($content, 'data-lightbox="true"')) {
            $content .= $this->renderLightboxOverlay();
        }

        // The block editor leaves an empty trailing paragraph after a Hero.
        $content = preg_replace(
            '/(<section class="vl-hero vl-hero-(?:split|cta)"[^>]*>[\s\S]*?<\/section>)(?:\s*<p>(?:\s|&nbsp;|&#160;|<br\s*\/?\s*>)*<\/p>)+/i',
            '$1',
            $content,
        ) ?? $content;

        // <!--spacer size=... unit=...--> — Invisible spacer
        if (str_contains($content, '<!--spacer')) {
            $content = preg_replace_callback(
                '/<!--spacer\s+size=(\d+)\s+unit=(\w+)-->/',
                function ($m) {
                    return '<div style="height:' . $m[1] . $m[2] . ';display:block" aria-hidden="true"></div>';
                },
                $content
            );
        }

        $content = $this->processInternalLinks($content);

        if (str_contains($content, '<img')) {
            $content = $this->localizeEmbeddedMediaAlt($content);
        }

        // Pasted raw <iframe> embeds (e.g. a YouTube iframe copied from the
        // share dialog) are stored without the .vl-video-embed aspect-ratio
        // wrapper. Their inline position:absolute style then escapes to the
        // viewport on the front end and covers the whole screen. Wrap them
        // here so all existing content (products, posts, pages) renders safe.
        if (str_contains($content, '<iframe')) {
            $content = $this->wrapBareVideoIframes($content);
        }

        // Self-hosted videos with <track> subtitles get a CC badge so visitors
        // can tell captions exist without hunting through the player menu.
        if (str_contains($content, '<track')) {
            $content = $this->addSubtitleBadges($content);
        }

        return $content;
    }

    /**
     * Wrap every <video> that carries a <track> in a positioned container with
     * a "CC" badge. The badge is decorative (the player's own caption button
     * stays the control) and uses inline styles so it needs no theme CSS.
     * Videos without tracks — YouTube/Vimeo iframes, carousel clips — are left
     * untouched, and already-wrapped videos are never wrapped twice.
     */
    private function addSubtitleBadges(string $content): string
    {
        $label = htmlspecialchars(__('This video has subtitles'), ENT_QUOTES, 'UTF-8');

        // The optional leading group lets an already-wrapped video be detected
        // (and left alone) — content can reach this pass twice in one request
        // (excerpt + full body), and a double wrapper would break the layout.
        //
        // The tempered lazy globs (?:(?!<\/video>)[\s\S])*? keep a match inside
        // ONE <video>…</video> block. Without them, a page holding a trackless
        // video followed by a subtitled one would let the gap lazily skip over
        // the first </video> into the second video, wrapping both in a single
        // container with one badge.
        return preg_replace_callback(
            '/(<div class="vl-video-sub-wrap"[^>]*>\s*)?'
            . '(<video\b[^>]*>(?:(?!<\/video>)[\s\S])*?<track\b(?:(?!<\/video>)[\s\S])*?<\/video>)/i',
            static function (array $m) use ($label): string {
                if (!empty($m[1])) {
                    return $m[0]; // already wrapped
                }

                // The tooltip lives on the badge itself, so it fires exactly
                // where the CC indicator is (a title on the wrapper would pop
                // anywhere over the video). The badge is deliberately tiny and
                // away from the bottom control bar, so giving it pointer events
                // costs a negligible click area.
                return '<div class="vl-video-sub-wrap" style="position:relative">'
                    . $m[2]
                    . '<span class="vl-video-sub-badge" role="note" '
                    . 'aria-label="' . $label . '" title="' . $label . '" '
                    . 'style="position:absolute;top:.5rem;right:.5rem;z-index:2;padding:.1rem .4rem;border-radius:.25rem;'
                    . 'background:rgba(17,24,39,.72);color:#fff;font-size:.75rem;font-weight:700;line-height:1.4;'
                    . 'letter-spacing:.02em;cursor:default;user-select:none;backdrop-filter:blur(2px)">CC</span>'
                    . '</div>';
            },
            $content,
        ) ?? $content;
    }

    private function localizeEmbeddedMediaAlt(string $content): string
    {
        $defaultLocale = strtolower(trim((string) ($this->settings->get('default_locale', 'en') ?? 'en')));
        $locale        = strtolower(trim((string) ($this->currentPost?->getLanguage() ?? $defaultLocale)));
        $fallback      = $this->settings->get('i18n_display_originals_fallback', '1') === '1';

        return preg_replace_callback(
            '/<img\b[^>]*>/i',
            function (array $matches) use ($locale, $defaultLocale, $fallback): string {
                $tag = $matches[0];
                if (!preg_match('/\bsrc=(["\'])([^"\']+)\1/i', $tag, $srcMatch)) {
                    return $tag;
                }

                $path = $this->extractUploadPath($srcMatch[2]);
                if ($path === null) {
                    return $tag;
                }

                $media = $this->media->findByPath($path);
                if ($media === null) {
                    return $tag;
                }

                $alt = $media->getLocalizedAltText($locale, $defaultLocale, $fallback);
                if ($alt === null || trim($alt) === '') {
                    return $tag;
                }

                return $this->setImgAlt($tag, $alt);
            },
            $content,
        ) ?? $content;
    }

    private function extractUploadPath(string $src): ?string
    {
        $path = parse_url($src, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = $src;
        }

        if (!preg_match('#/uploads/(.+)$#i', $path, $matches)) {
            return null;
        }

        $relative = ltrim($matches[1], '/');
        if (preg_match('#^(.+)/([^/]+?)-(small|medium|large|webp)\.([a-z0-9]+)$#i', $relative, $parts)) {
            $relative = $parts[1] . '/' . $parts[2] . '.' . $parts[4];
        }

        return $relative;
    }

    private function setImgAlt(string $imgTag, string $alt): string
    {
        $escaped = htmlspecialchars($alt, ENT_QUOTES, 'UTF-8');
        if (preg_match('/\balt=(["\']).*?\1/i', $imgTag)) {
            return preg_replace('/\balt=(["\']).*?\1/i', 'alt="' . $escaped . '"', $imgTag) ?? $imgTag;
        }

        return preg_replace('/<img\b/i', '<img alt="' . $escaped . '"', $imgTag) ?? $imgTag;
    }

    /**
     * Remove interactive/editor shortcodes from syndicated content (RSS, etc.).
     */
    public function stripForFeed(string $content): string
    {
        if ($content === '') {
            return '';
        }

        $patterns = [
            '/<p>\s*\[form\s+slug=(?:["\']|&quot;)([a-z0-9_-]+)(?:["\']|&quot;)\s*\]\s*<\/p>/iu',
            '/\[form\s+slug=(?:["\']|&quot;)([a-z0-9_-]+)(?:["\']|&quot;)\s*\]/iu',
            '/<div[^>]*data-form-slug=(?:["\']|&quot;)[^"\']+(?:["\']|&quot;)[^>]*>.*?<\/div>/is',
            '/<p>\s*\[newsletter_form(?:[^\]]*)\]\s*<\/p>/iu',
            '/\[newsletter_form(?:[^\]]*)\]/iu',
            '/<p>\s*\[toc(?:[^\]]*)\]\s*<\/p>/iu',
            '/\[toc(?:[^\]]*)\]/iu',
            '/<p>\s*\[pages(?:[^\]]*)\]\s*<\/p>/iu',
            '/\[pages(?:[^\]]*)\]/iu',
            '/\[field\s+name=(?:["\']|&quot;)[^"\']+(?:["\']|&quot;)\s*\]/iu',
            '/<p>\s*\[ad\]\s*<\/p>/iu',
            '/\[ad\]/iu',
            '/<p>\s*\[carousel(?:[^\]]*)\]\s*<\/p>/iu',
            '/\[carousel(?:[^\]]*)\]/iu',
            '/<p>\s*\[product(?:[^\]]*)\]\s*<\/p>/iu',
            '/\[product(?:[^\]]*)\]/iu',
            '/\[link_button(?:[^\]]*)\].*?\[\/link_button\]/isu',
            '/\[card_group(?:[^\]]*)\][\s\S]*?\[\/card_group\]/iu',
            '/\[card_item(?:[^\]]*)\].*?\[\/card_item\]/isu',
            '/<!--spacer\s+size=\d+\s+unit=\w+-->/iu',
            '/<div[^>]*class="[^"]*vl-spacer-block[^"]*"[^>]*>\s*<\/div>/iu',
        ];

        foreach ($patterns as $pattern) {
            $content = preg_replace($pattern, '', $content) ?? $content;
        }

        $content = preg_replace('/<p>\s*<\/p>/iu', '', $content) ?? $content;

        return trim($content);
    }

    private function processCarousel(string $content): string
    {
        return preg_replace_callback(
            '/<p>\s*\[carousel(?:\s+([^\]]*))?\]\s*<\/p>|\[carousel(?:\s+([^\]]*))?\]/',
            function ($matches) {
                $attrsRaw = !empty($matches[1]) ? $matches[1] : (!empty($matches[2]) ? $matches[2] : '');
                $attrs    = $this->parseAttributes($attrsRaw);

                $images     = array_filter(explode(',', $attrs['images'] ?? ''));
                $autoplay   = ($attrs['autoplay'] ?? 'true') === 'true';
                $duration   = (int) ($attrs['duration'] ?? 5000);
                $arrows     = ($attrs['arrows'] ?? 'true')    === 'true';
                $dots       = ($attrs['dots'] ?? 'true')      === 'true';
                $pauseBtn   = ($attrs['pauseBtn'] ?? 'false') === 'true';
                $fit        = $attrs['fit'] ?? 'cover';
                $lightbox   = ($attrs['lightbox'] ?? 'false') === 'true';
                $fit        = in_array($fit, ['cover', 'contain', 'fill', 'none', 'scale-down']) ? $fit : 'cover';
                $height     = $attrs['height']     ?? '200px';
                $transition = $attrs['transition'] ?? 'fade';

                $bgColor = $attrs['bg'] ?? '';

                // Theme-aware colors
                $theme = $this->settings->get('active_theme', 'default');
                switch ($theme) {
                    case 'dark':
                        $arrowBg        = 'rgba(255,255,255,0.18)';
                        $arrowHoverBg   = 'rgba(255,255,255,0.32)';
                        $arrowColor     = '#fff';
                        $dotPillBg      = 'rgba(0,0,0,0.45)';
                        $dotColor       = '#fff';
                        $dotColorActive = '#fff';
                        $slideDefaultBg = '#1f2937';
                        break;
                    case 'vivid':
                        $arrowBg        = 'rgba(16,185,129,0.25)';
                        $arrowHoverBg   = 'rgba(16,185,129,0.5)';
                        $arrowColor     = '#fff';
                        $dotPillBg      = 'rgba(16,185,129,0.45)';
                        $dotColor       = 'rgba(255,255,255,0.7)';
                        $dotColorActive = '#fff';
                        $slideDefaultBg = '#f3f4f6';
                        break;
                    default:
                        $arrowBg        = 'rgba(0,0,0,0.25)';
                        $arrowHoverBg   = 'rgba(0,0,0,0.4)';
                        $arrowColor     = '#fff';
                        $dotPillBg      = 'rgba(0,0,0,0.35)';
                        $dotColor       = 'rgba(255,255,255,0.75)';
                        $dotColorActive = '#fff';
                        $slideDefaultBg = '#f3f4f6';
                        break;
                }
                $slideBg = $bgColor !== '' ? $bgColor : $slideDefaultBg;

                if (empty($images)) {
                    return '';
                }

                $this->hadShortcodes = true;

                $id = 'carousel-' . bin2hex(random_bytes(4));

                // Build x-data string (no lightbox state — uses window event)
                $xData = '{'
                    . 'active:0,'
                    . 'itemsCount:' . count($images) . ','
                    . 'autoplay:' . ($autoplay ? 'true' : 'false') . ','
                    . 'duration:' . $duration . ','
                    . 'paused:false,'
                    . 'timer:null,'
                    // NOTE: inside $nextTick Alpine resolves $el to the element that
                    // triggered the current action (e.g. the arrow button), not the
                    // carousel root — so always look the root up via closest() here.
                    . 'schedule(){if(!this.autoplay||this.paused||document.hidden)return;clearTimeout(this.timer);'
                    . 'this.$nextTick(()=>{'
                    . 'let root=this.$el.closest(".vl-carousel")||this.$el;'
                    . 'let v=root.querySelectorAll(".vl-carousel-slide")[this.active].querySelector("video");'
                    . 'if(v){v.currentTime=0;v.play().catch(()=>{});return}'
                    . 'this.timer=setTimeout(()=>{if(!this.paused)this.next()},this.duration)'
                    . '})},'
                    . 'next(){this.active=(this.active+1)%this.itemsCount;this.schedule()},'
                    . 'prev(){this.active=(this.active-1+this.itemsCount)%this.itemsCount;this.schedule()},'
                    . 'goTo(i){this.active=i;this.schedule()},'
                    . 'togglePause(){this.paused=!this.paused;if(this.paused){clearTimeout(this.timer)}else{this.schedule()}},'
                    . ($lightbox ? 'openLightbox(src){window.dispatchEvent(new CustomEvent(\'lightbox-open\',{detail:src}))},' : '')
                    . 'init(){this.schedule();document.addEventListener("visibilitychange",()=>{if(document.hidden){clearTimeout(this.timer)}else{this.schedule()}})}'
                    . '}';

                $html = '<div id="' . $id . '" class="vl-carousel relative overflow-hidden rounded-xl mt-0 mb-8 group" style="height:' . $height . ';padding-bottom:0.75rem;background:' . $slideBg . '" x-data="' . htmlspecialchars($xData, ENT_QUOTES, 'UTF-8') . '">';

                // Build transition attributes based on transition type
                $transEnter = '';
                $transLeave = '';
                switch ($transition) {
                    case 'slide-left':
                        $transEnter = ' x-transition:enter="transition ease-out duration-500"'
                            . ' x-transition:enter-start="opacity-0 translate-x-full"'
                            . ' x-transition:enter-end="opacity-100 translate-x-0"'
                            . ' x-transition:leave="transition ease-in duration-500"'
                            . ' x-transition:leave-start="opacity-100 translate-x-0"'
                            . ' x-transition:leave-end="opacity-0 -translate-x-full"';
                        break;
                    case 'slide-right':
                        $transEnter = ' x-transition:enter="transition ease-out duration-500"'
                            . ' x-transition:enter-start="opacity-0 -translate-x-full"'
                            . ' x-transition:enter-end="opacity-100 translate-x-0"'
                            . ' x-transition:leave="transition ease-in duration-500"'
                            . ' x-transition:leave-start="opacity-100 translate-x-0"'
                            . ' x-transition:leave-end="opacity-0 translate-x-full"';
                        break;
                    case 'slide-up':
                        $transEnter = ' x-transition:enter="transition ease-out duration-500"'
                            . ' x-transition:enter-start="opacity-0 translate-y-full"'
                            . ' x-transition:enter-end="opacity-100 translate-y-0"'
                            . ' x-transition:leave="transition ease-in duration-500"'
                            . ' x-transition:leave-start="opacity-100 translate-y-0"'
                            . ' x-transition:leave-end="opacity-0 -translate-y-full"';
                        break;
                    case 'zoom':
                        $transEnter = ' x-transition:enter="transition ease-out duration-500"'
                            . ' x-transition:enter-start="opacity-0 scale-75"'
                            . ' x-transition:enter-end="opacity-100 scale-100"'
                            . ' x-transition:leave="transition ease-in duration-500"'
                            . ' x-transition:leave-start="opacity-100 scale-100"'
                            . ' x-transition:leave-end="opacity-0 scale-110"';
                        break;
                    case 'none':
                        $transEnter = '';
                        break;
                    case 'fade':
                    default:
                        $transEnter = ' x-transition:enter="transition ease-out duration-500"'
                            . ' x-transition:enter-start="opacity-0 scale-105"'
                            . ' x-transition:enter-end="opacity-100 scale-100"'
                            . ' x-transition:leave="transition ease-in duration-500"'
                            . ' x-transition:leave-start="opacity-100 scale-100"'
                            . ' x-transition:leave-end="opacity-0 scale-95"';
                        break;
                }

                // Slides container (absolutely positioned to fill parent minus padding-bottom)
                $html .= '<div style="position:absolute;top:0;left:0;right:0;bottom:0.75rem">';
                foreach (array_values($images) as $index => $url) {
                    $html .= '<div x-show="active === ' . $index . '"'
                            . $transEnter
                            . ' class="absolute inset-0 vl-carousel-slide"'
                            . ($index > 0 ? ' style="display:none"' : '') . '>';

                    if ($this->isVideo($url)) {
                        $html .= $this->renderVideoSlide($url, $fit, $autoplay);
                    } else {
                        $imgStyle = 'height:100%;width:100%;object-fit:' . $fit . ';object-position:center;margin:6px';
                        if ($lightbox) {
                            $imgStyle .= ';cursor:pointer';
                        }
                        $clickAttr = $lightbox ? ' @click="openLightbox(\'' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '\')"' : '';
                        $titleAttr = '';
                        if ($lightbox) {
                            $titleAttr = ' title="'
                                . htmlspecialchars(__('Click to enlarge'), ENT_QUOTES, 'UTF-8');
                            $titleAttr .= '"';
                        }
                        $html .= '<img src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"';
                        $html .= ' alt="" loading="lazy" style="' . $imgStyle . '"';
                        $html .= $titleAttr . $clickAttr . '>';
                    }
                    $html .= '</div>';
                }
                $html .= '</div>';

                // Dots (outside slides container, directly inside outer container)
                if ($dots && count($images) > 1) {
                    $html .= '<div style="position:absolute;bottom:0.75rem;left:50%;transform:translateX(-50%);display:flex;gap:0.4rem;background:' . $dotPillBg . ';padding:0.4rem 0.6rem;border-radius:9999px;z-index:10">';
                    foreach (array_values($images) as $index => $url) {
                        $dotLabel = htmlspecialchars(__('Go to slide :current', [':current' => (string) ($index + 1)]), ENT_QUOTES, 'UTF-8');
                        $html .= '<button type="button" @click="goTo(' . $index . ')"
                                          aria-label="' . $dotLabel . '"
                                          title="' . $dotLabel . '"
                                          :aria-current="active === ' . $index . ' ? \'true\' : \'false\'"
                                          :class="active === ' . $index . ' ? \'vl-cdot vl-cdot-active\' : \'vl-cdot\'"
                                          class="vl-cdot"
                                          data-active-bg="' . $dotColorActive . '"
                                          data-inactive-bg="' . $dotColor . '"
                                          style="background:' . $dotColor . '"></button>';
                    }
                    $html .= '</div>';
                    $html .= '<style>#' . $id . ' .vl-cdot{width:0.75rem;height:0.75rem;border-radius:9999px;border:none;cursor:pointer;padding:0;flex-shrink:0;transition:all .3s ease;display:inline-block}#' . $id . ' .vl-cdot-active{width:1.5rem!important}</style>';
                }

                // Arrows
                if ($arrows && count($images) > 1) {
                    $prevLabel = htmlspecialchars(__('Previous slide'), ENT_QUOTES, 'UTF-8');
                    $nextLabel = htmlspecialchars(__('Next slide'), ENT_QUOTES, 'UTF-8');
                    $html .= '<button @click="prev()" aria-label="' . $prevLabel . '" title="' . $prevLabel . '" class="vl-c-arrow" style="position:absolute;left:1rem;top:50%;transform:translateY(-50%);border:none;border-radius:9999px;padding:0.5rem;color:' . $arrowColor . ';cursor:pointer;background:' . $arrowBg . ';box-shadow:0 4px 6px rgba(0,0,0,0.1);backdrop-filter:blur(4px);transition:all 0.2s;opacity:0;display:flex;align-items:center;justify-content:center;z-index:15" onmouseover="this.style.background=\'' . $arrowHoverBg . '\'" onmouseout="this.style.background=\'' . $arrowBg . '\'">
                        <svg style="width:1.5rem;height:1.5rem" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    </button>';
                    $html .= '<button @click="next()" aria-label="' . $nextLabel . '" title="' . $nextLabel . '" class="vl-c-arrow" style="position:absolute;right:1rem;top:50%;transform:translateY(-50%);border:none;border-radius:9999px;padding:0.5rem;color:' . $arrowColor . ';cursor:pointer;background:' . $arrowBg . ';box-shadow:0 4px 6px rgba(0,0,0,0.1);backdrop-filter:blur(4px);transition:all 0.2s;opacity:0;display:flex;align-items:center;justify-content:center;z-index:15" onmouseover="this.style.background=\'' . $arrowHoverBg . '\'" onmouseout="this.style.background=\'' . $arrowBg . '\'">
                        <svg style="width:1.5rem;height:1.5rem" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </button>';
                    $html .= '<style>#' . $id . ' .vl-c-arrow{opacity:0;transition:opacity .2s}#' . $id . ':hover .vl-c-arrow{opacity:1!important}</style>';
                }

                // Pause button (more visible background)
                if ($pauseBtn && count($images) > 1) {
                    // Dynamic label follows toggle state; JS-escape first (single-quote),
                    // then HTML-escape so Alpine receives a valid string in any locale.
                    $pauseLabel = htmlspecialchars(addcslashes(__('Pause carousel'), "'\\"), ENT_QUOTES, 'UTF-8');
                    $playLabel  = htmlspecialchars(addcslashes(__('Play carousel'), "'\\"), ENT_QUOTES, 'UTF-8');
                    $pauseExpr  = 'paused ? \'' . $playLabel . '\' : \'' . $pauseLabel . '\'';
                    $html .= '<button @click="togglePause()" :aria-label="' . $pauseExpr . '" :title="' . $pauseExpr . '" style="position:absolute;bottom:0.75rem;right:0.75rem;z-index:20;border:none;border-radius:9999px;padding:0.375rem;color:white;cursor:pointer;background:rgba(0,0,0,0.4);backdrop-filter:blur(4px);transition:background 0.2s;display:flex;align-items:center;justify-content:center" onmouseover="this.style.background=\'rgba(0,0,0,0.6)\'" onmouseout="this.style.background=\'rgba(0,0,0,0.4)\'">
                        <svg x-show="!paused" style="display:block;width:1.25rem;height:1.25rem" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6"/></svg>
                        <svg x-show="paused" style="display:none;width:1.25rem;height:1.25rem" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/></svg>
                    </button>';
                }

                $html .= '</div>';

                // Lightbox overlay (standalone — own x-data, :style controls display)
                if ($lightbox) {
                    $html .= $this->renderLightboxOverlay();
                }

                return $html;
            },
            $content
        );
    }

    /**
     * Standalone lightbox overlay — rendered once per page. Reused by carousels
     * and by inline images that opt into the lightbox, guarded by lightboxRendered.
     */
    private function renderLightboxOverlay(): string
    {
        if ($this->lightboxRendered) {
            return '';
        }
        $this->lightboxRendered = true;

        return '<div x-data="{ lbActive: false, lbSrc: \'\' }"
              @lightbox-open.window="lbSrc = $event.detail; lbActive = true"
              @keydown.escape.window="lbActive = false"
              @click="lbActive = false"
              :style="\'position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.85);\' + (lbActive ? \'display:flex;\' : \'display:none;\') + \'align-items:center;justify-content:center;cursor:pointer\'">
            <img :src="lbSrc" style="max-height:90%;max-width:90%;object-fit:contain;border-radius:0.5rem;box-shadow:0 20px 60px rgba(0,0,0,0.5)">
        </div>';
    }

    private function parseAttributes(string $text): array
    {
        $attributes = [];

        // Matches key="value" or key='value' or key=&quot;value&quot;. The closing
        // quote must match the opening one, otherwise an apostrophe inside a
        // double-quoted value (e.g. button="Get to know when it's out") would be
        // mistaken for a closing delimiter and truncate the value.
        preg_match_all('/([a-z0-9_-]+)=("|\'|&quot;)(.*?)\2/i', $text, $matches);

        if (!empty($matches[1])) {
            foreach ($matches[1] as $index => $key) {
                $attributes[$key] = $matches[3][$index];
            }
        }

        return $attributes;
    }

    /**
     * Validate a user-supplied width for constrained blocks (e.g. newsletter form).
     * Accepts "auto", a percentage or pixel value. Returns the safe CSS value,
     * or an empty string when the block should render full width (default).
     */
    private function normalizeWidth(string $raw): string
    {
        $width = strtolower(trim($raw));

        if ($width === '' || $width === 'auto' || $width === '100%') {
            return '';
        }

        if (preg_match('/^\d+(\.\d+)?(px|%)$/', $width) !== 1) {
            return '';
        }

        return $width;
    }

    /**
     * Scan the content for headings up to max_level, inject id="…" anchors into each
     * heading tag, then replace [toc] / [toc max_level="N"] with a <nav> list.
     */
    private function processToc(string $content): string
    {
        // Parse max_level from shortcode attribute (default 3 = h1–h3)
        $maxLevel = 3;
        if (preg_match('/\[toc\s+max_level=["\']?(\d)["\']?\]/', $content, $attrMatch)) {
            $maxLevel = max(1, min(3, (int) $attrMatch[1]));
        }

        $headings = [];
        $usedIds  = [];

        // Check if $this->currentPost has multi-page content
        $fullBody = $this->currentPost ? ($this->currentPost->getMetaValue('_full_body') ?? $this->currentPost->getBody() ?? '') : '';
        $pattern  = '/(?:<p\b[^>]*>\s*)?<!--nextpage(?:\s+title=("|\'|&quot;)(.*?)\1)?\s*-->(?:\s*<\/p>)?/i';

        if ($fullBody !== '' && preg_match($pattern, $fullBody)) {
            // Multi-page post: scan headings across all page segments
            $segments          = preg_split($pattern, $fullBody);
            $currentPage       = max(1, (int) ($this->currentPost?->getMetaValue('_content_page') ?? ($_GET['page'] ?? 1)));
            $usedIdsForSegment = [];

            foreach ($segments as $sIndex => $segment) {
                $pageNum = $sIndex + 1;
                // Before processing the current page segment, save the used IDs state
                if ($pageNum === $currentPage) {
                    $usedIdsForSegment = $usedIds;
                }

                preg_match_all('/<(h[1-3])([^>]*)>(.*?)<\/h[1-3]>/si', $segment, $mMatches, PREG_SET_ORDER);
                foreach ($mMatches as $m) {
                    $tag   = $m[1];
                    $text  = html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $level = (int) substr($tag, 1);

                    if ($level > $maxLevel) {
                        continue;
                    }

                    $id = strtolower(trim($text));
                    $id = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $id);
                    $id = preg_replace('/[\s]+/', '-', $id);
                    $id = trim($id, '-') ?: 'section';

                    $base  = $id;
                    $count = 1;
                    while (isset($usedIds[$id])) {
                        $id = $base . '-' . $count++;
                    }
                    $usedIds[$id] = true;

                    // Build href relative to current page
                    if ($pageNum === $currentPage) {
                        $href = '#' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
                    } else {
                        $queryParams         = $_GET;
                        $queryParams['page'] = $pageNum;
                        $qs                  = http_build_query($queryParams);
                        $href                = ($qs !== '' ? '?' . $qs : '') . '#' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
                    }

                    $headings[] = [
                        'tag'   => $tag,
                        'id'    => $id,
                        'text'  => $text,
                        'href'  => $href,
                        'level' => $level,
                    ];
                }
            }

            // Also inject id anchors into the headings of the current $content segment
            $content = preg_replace_callback(
                '/<(h[1-3])([^>]*)>(.*?)<\/h[1-3]>/si',
                function (array $m) use (&$usedIdsForSegment): string {
                    $tag   = $m[1];
                    $attrs = $m[2];
                    $text  = html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

                    $id = strtolower(trim($text));
                    $id = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $id);
                    $id = preg_replace('/[\s]+/', '-', $id);
                    $id = trim($id, '-') ?: 'section';

                    $base  = $id;
                    $count = 1;
                    while (isset($usedIdsForSegment[$id])) {
                        $id = $base . '-' . $count++;
                    }
                    $usedIdsForSegment[$id] = true;

                    if (!str_contains($attrs, 'id=')) {
                        $attrs .= ' id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"';
                    }

                    return "<{$tag}{$attrs}>{$m[3]}</{$tag}>";
                },
                $content,
            );
        } else {
            // Single page content (standard processing)
            $content = preg_replace_callback(
                '/<(h[1-3])([^>]*)>(.*?)<\/h[1-3]>/si',
                function (array $m) use (&$headings, &$usedIds, $maxLevel): string {
                    $tag   = $m[1];
                    $attrs = $m[2];
                    $text  = html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $level = (int) substr($tag, 1);

                    $id = strtolower(trim($text));
                    $id = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $id);
                    $id = preg_replace('/[\s]+/', '-', $id);
                    $id = trim($id, '-') ?: 'section';

                    $base  = $id;
                    $count = 1;
                    while (isset($usedIds[$id])) {
                        $id = $base . '-' . $count++;
                    }
                    $usedIds[$id] = true;

                    if ($level <= $maxLevel) {
                        $headings[] = [
                            'tag'   => $tag,
                            'id'    => $id,
                            'text'  => $text,
                            'href'  => '#' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8'),
                            'level' => $level,
                        ];
                    }

                    if (!str_contains($attrs, 'id=')) {
                        $attrs .= ' id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"';
                    }

                    return "<{$tag}{$attrs}>{$m[3]}</{$tag}>";
                },
                $content,
            );
        }

        if (empty($headings)) {
            // No qualifying headings found — silently remove the shortcode
            return preg_replace('/<p>\s*\[toc(?:[^\]]*)\]\s*<\/p>|\[toc(?:[^\]]*)\]/', '', $content);
        }

        // Build the nav list — indent relative to the shallowest heading present,
        // so an H2-only list (e.g. [toc max_level="2"] with no H1 in the body)
        // starts at the first column instead of looking like a second level.
        $tocHtml  = '<nav class="vl-toc" aria-label="Table of Contents">';
        $tocHtml .= '<p class="vl-toc-title">' . __('Contents') . '</p>';
        $tocHtml .= '<ol class="vl-toc-list">';

        $minLevel = min(array_column($headings, 'level'));

        foreach ($headings as $h) {
            $class = 'vl-toc-item vl-toc-h' . ($h['level'] - $minLevel + 1);
            $label = htmlspecialchars($h['text'], ENT_QUOTES, 'UTF-8');

            $tocHtml .= '<li class="' . $class . '"><a href="' . $h['href'] . '">' . $label . '</a></li>';
        }

        $tocHtml .= '</ol></nav>';

        // Replace <p>[toc ...]</p> or bare [toc ...] with the generated nav
        return preg_replace('/<p>\s*\[toc(?:[^\]]*)\]\s*<\/p>|\[toc(?:[^\]]*)\]/', $tocHtml, $content);
    }

    /**
     * Render a nested list of child pages.
     * [pages] — children of current page (if available)
     * [pages parent="slug"] — children of specified page
     * [pages max_level="N"] — nesting depth (default 1, max 3)
     */
    private function processPages(string $content): string
    {
        $maxLevel   = 1;
        $parentSlug = null;

        if (preg_match('/\[pages\s+([^\]]*)\]/', $content, $m)) {
            $attrs = $m[1];
            if (preg_match('/max_level=["\']?(\d)["\']?/', $attrs, $am)) {
                $maxLevel = max(1, min(3, (int) $am[1]));
            }
            if (preg_match('/parent=["\']([^"\']+)["\']/', $attrs, $pm)) {
                $parentSlug = $pm[1];
            }
        }

        $parentId = null;
        if ($parentSlug !== null) {
            $parentPage = $this->posts->findBySlug(new Slug($parentSlug), 'page');
            if ($parentPage !== null) {
                $parentId = $parentPage->getId();
            }
        } elseif ($this->currentPost !== null) {
            $parentId = $this->currentPost->getId();
        }

        $children = $this->posts->findPagesByParent($parentId);

        if (empty($children)) {
            return preg_replace('/<p>\s*\[pages(?:[^\]]*)\]\s*<\/p>|\[pages(?:[^\]]*)\]/', '', $content);
        }

        $html = $this->renderPageTree($children, $maxLevel, 1);
        return preg_replace('/<p>\s*\[pages(?:[^\]]*)\]\s*<\/p>|\[pages(?:[^\]]*)\]/', $html, $content);
    }

    private function processMetaFields(string $content): string
    {
        if ($this->currentPost === null) {
            return preg_replace('/\[field\s+name=["\']?([^"\']+)["\']?\]/', '', $content);
        }

        return preg_replace_callback(
            '/\[field\s+name=(?:["\']|&quot;)([^"\'&]+)(?:["\']|&quot;)\s*\]/',
            fn ($m) => $this->resolveMetaValue($m[1]),
            $content
        );
    }

    /**
     * Replace bare {{ fieldname }} placeholders with the matching meta value.
     * Only fields that exist on the current post's meta are replaced — unknown
     * placeholders are left untouched.
     */
    private function processBareMetaFields(string $content): string
    {
        $meta = $this->currentPost?->getMeta() ?? [];

        foreach (array_keys($meta) as $name) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            $pattern = '/\{\{\s*' . preg_quote($name, '/') . '\s*\}\}/';
            $content = preg_replace($pattern, $this->resolveMetaValue($name), $content);
        }

        return $content;
    }

    private function resolveMetaValue(string $name): string
    {
        if ($this->currentPost === null) {
            return '';
        }

        $value = $this->currentPost->getMetaValue($name, '');
        $raw   = (string) $value;

        // If the value is a positive integer, try to resolve as a media item and render an img tag
        $trimmed = trim($raw);
        if (ctype_digit($trimmed) && $trimmed > 0) {
            $media = $this->media->findById((int) $trimmed);
            if ($media !== null) {
                $url = $this->basePath . ($media->getThumbnailUrl('webp') ?? $media->getUrl());
                $url = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
                return '<img src="' . $url . '" alt="" class="w-full h-auto rounded-lg">';
            }
        }

        return htmlspecialchars($raw, ENT_QUOTES, 'UTF-8');
    }

    private function renderPageTree(array $pages, int $maxLevel, int $currentLevel): string
    {
        if ($currentLevel > $maxLevel || empty($pages)) {
            return '';
        }

        $pagePrefix = $this->settings->get('routing_page_prefix', '');
        $path       = $this->basePath . ($pagePrefix ? '/' . ltrim($pagePrefix, '/') : '');

        $html = '<ul class="vl-pages-list">';
        foreach ($pages as $page) {
            $fullPath = $this->posts->buildPagePath($page);
            $title    = htmlspecialchars($page->getTitle(), ENT_QUOTES, 'UTF-8');
            $url      = $path . '/' . ltrim($fullPath, '/');
            $html    .= '<li><a href="' . $url . '">' . $title . '</a>';

            if ($currentLevel < $maxLevel) {
                $grandchildren = $this->posts->findPagesByParent($page->getId());
                if (!empty($grandchildren)) {
                    $html .= $this->renderPageTree($grandchildren, $maxLevel, $currentLevel + 1);
                }
            }

            $html .= '</li>';
        }
        $html .= '</ul>';
        return $html;
    }

    private function decodeAttrs(string $attrs): string
    {
        // ENT_HTML5 also decodes &apos; (apostrophe), which Tiptap/editor content
        // may emit; the default HTML4 doctype leaves it as-is.
        return html_entity_decode($attrs, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function renderForm(string $slug): string
    {
        if ($this->settings->get('form_builder_enabled', '1') === '0') {
            return '';
        }

        $this->hadShortcodes = true;

        try {
            $form = $this->forms->findBySlug(new Slug($slug));
        } catch (\Exception) {
            return '';
        }

        if ($form === null || !$form->isEnabled()) {
            return '';
        }

        // Multi-language resolution
        if ($this->settings->get('multi_language_content', '0') === '1' && $this->currentPost !== null) {
            $postLanguage = $this->currentPost->getLanguage();
            if ($form->getLanguage() !== $postLanguage) {
                $originalId   = $form->getTranslationOf() ?? $form->getId();
                $translations = $this->forms->findTranslations($originalId);
                $matchedForm  = null;
                foreach ($translations as $t) {
                    if ($t->getLanguage() === $postLanguage && $t->isEnabled()) {
                        $matchedForm = $t;
                        break;
                    }
                }

                if ($matchedForm !== null) {
                    $form = $matchedForm;
                } else {
                    // Fallback check
                    $fallback = $this->settings->get('i18n_display_originals_fallback', '1') === '1';
                    if (!$fallback) {
                        return ''; // Fallback is disabled, don't show the form
                    }
                    // Fallback to original
                    if ($form->getTranslationOf() !== null) {
                        $originalForm = $this->forms->findById($originalId);
                        if ($originalForm !== null && $originalForm->isEnabled()) {
                            $form = $originalForm;
                        } else {
                            return '';
                        }
                    }
                }
            }
        }

        // Access control — the same restrictions the submit endpoint enforces,
        // so a restricted form is never rendered for visitors who cannot use it.
        $accessCheck = $this->formAccess->evaluate($form);
        if (!$accessCheck['has_access']) {
            return $this->renderFormAccessNotice($accessCheck);
        }

        // A captcha provider without its API keys would reject every
        // submission, so the form is withheld instead of shown broken.
        if ($this->formCaptcha->isMisconfigured($form)) {
            $this->formCaptcha->logUnavailable($form);

            return $this->renderFormUnavailableNotice();
        }

        $csrf          = htmlspecialchars($this->csrf->getToken(), ENT_QUOTES, 'UTF-8');
        $slug          = htmlspecialchars((string) $form->getSlug(), ENT_QUOTES, 'UTF-8');
        $action        = $this->basePath . '/form/' . $slug;
        $formId        = 'vl-form-' . $slug;
        $formKey       = 'vl_form_submitted_' . $form->getId();
        $allowMultiple = (bool) ($form->getSettings()['allow_multiple'] ?? '1');

        $html  = '<div class="vl-embedded-form mt-0 mb-8" id="' . $formId . '-wrapper">';
        $html .= '<form method="POST" action="' . $action . '" class="space-y-5" id="' . $formId . '">';
        $html .= '<input type="hidden" name="_csrf_token" value="' . $csrf . '">';
        $html .= '<div class="flex flex-wrap -mx-2.5">';

        foreach ($form->getFields() as $field) {
            $html .= $this->renderField($field);
        }

        $html .= '</div>';

        // Captcha — honours the form's captcha_type (math / honeypot /
        // hcaptcha / recaptcha / turnstile), exactly like the standalone
        // form page does, so the widget always matches the server-side check.
        $captchaType = $form->getCaptchaType();
        $html       .= $this->renderEmbeddedCaptcha($form, $formId, $captchaType);

        $label = htmlspecialchars($form->getSubmitLabel() ?? 'Submit', ENT_QUOTES, 'UTF-8');
        $html .= '<button type="submit" class="rounded-lg btn-primary px-6 py-3 text-sm ';
        $html .= 'font-medium text-white hover:bg-gray-700">' . $label . '</button>';
        $html .= '</form>';
        $html .= '<div id="' . $formId . '-message" class="hidden rounded-lg p-4 text-sm"></div>';
        $html .= '</div>';

        // Inline AJAX handler so embedded form submits without page redirect
        $html .= '<script>';
        $html .= '(function(){';
        $html .= 'var f=document.getElementById(' . json_encode($formId) . ');';
        $html .= 'if(!f)return;';
        if (!$allowMultiple) {
            $html .= 'var K=' . json_encode($formKey) . ';';
            $html .= 'if(localStorage.getItem(K)){';
            $html .= 'var w0=document.getElementById(' . json_encode($formId . '-wrapper') . ');';
            $html .= 'if(w0){w0.innerHTML="<div class=\"rounded-lg bg-blue-50 border border-blue-200 p-6 text-center text-blue-800 text-sm\">' . __('You have already submitted this form.') . '</div>";return;}';
            $html .= '}';
        }
        $html .= 'f.addEventListener("submit",function(e){';
        $html .= 'e.preventDefault();';
        $html .= 'var btn=f.querySelector("button[type=submit]");';
        $html .= 'btn.disabled=true;btn.textContent="Sending…";';
        $html .= 'fetch(f.action,{method:"POST",headers:{"X-Requested-With":"XMLHttpRequest"},body:new FormData(f)})';
        $html .= '.then(function(r){return r.json()})';
        $html .= '.then(function(d){';
        $html .= 'var w=document.getElementById(' . json_encode($formId . '-wrapper') . ');';
        $html .= 'var m=document.getElementById(' . json_encode($formId . '-message') . ');';
        $html .= 'if(d.success){';
        $html .= 'f.style.display="none";';
        if (!$allowMultiple) {
            $html .= 'localStorage.setItem(' . json_encode($formKey) . ',"1");';
        }
        $html .= 'm.className="rounded-lg p-4 text-sm bg-green-50 text-green-800 border border-green-200";';
        $html .= 'm.textContent=d.message;m.classList.remove("hidden");';
        $html .= '}else{';
        $html .= 'm.className="rounded-lg p-4 text-sm bg-red-50 text-red-800 border border-red-200";';
        $html .= 'm.textContent=d.message||"An error occurred.";m.classList.remove("hidden");';
        $html .= 'btn.disabled=false;btn.textContent=' . json_encode($form->getSubmitLabel() ?? 'Submit') . ';';
        if (!$allowMultiple) {
            // Server rejected this browser/email combination as a duplicate —
            // lock the form locally too so a different email cannot be tried.
            $html .= 'if(d.already_submitted){';
            $html .= 'f.style.display="none";';
            $html .= 'localStorage.setItem(' . json_encode($formKey) . ',"1");';
            $html .= 'btn.disabled=true;';
            $html .= 'm.className="rounded-lg p-4 text-sm bg-blue-50 text-blue-800 border border-blue-200";';
            $html .= '}';
        }
        if ($captchaType === 'math') {
            $html .= 'if(d.captcha_question){';
            $html .= 'var capDiv=document.getElementById(' . json_encode($formId . '-captcha') . ');';
            $html .= 'if(capDiv){var lbl=capDiv.querySelector("label");';
            $html .= 'lbl.innerHTML=d.captcha_question+" = ? <span class=\"text-red-500\">*</span> ";';
            $html .= 'lbl.innerHTML+="<span class=\"text-xs text-gray-400 font-normal ml-1\">(spam protection)</span>";';
            $html .= 'capDiv.querySelector("input[name=captcha]").value="";}}';
        }
        $html .= '}})';
        $html .= '.catch(function(){';
        $html .= 'var m=document.getElementById(' . json_encode($formId . '-message') . ');';
        $html .= 'm.className="rounded-lg p-4 text-sm bg-red-50 text-red-800 border border-red-200";';
        $html .= 'm.textContent="Network error. Please try again.";m.classList.remove("hidden");';
        $html .= 'btn.disabled=false;btn.textContent=' . json_encode($form->getSubmitLabel() ?? 'Submit') . ';';
        $html .= '});';
        $html .= '});';
        $html .= '})();';
        $html .= '</script>';

        return $html;
    }

    /**
     * Renders the notice shown when a visitor may not access a form embedded
     * through the shortcode — mirrors the wording of the standalone form page.
     *
     * @param array{has_access: bool, require_login: bool, missing_membership_tags: bool, access_tags: array} $access
     */
    private function renderFormAccessNotice(array $access): string
    {
        if ($access['require_login']) {
            return '<div class="rounded-lg border border-gray-200 dark:border-gray-700 '
                . 'bg-gray-50 dark:bg-gray-800/60 p-6 my-4 text-center">'
                . '<p class="text-sm font-semibold text-gray-900 dark:text-gray-100">'
                . __('Sign in to access this form') . '</p>'
                . '<p class="mt-1 text-sm text-gray-600 dark:text-gray-400">'
                . __('This form is restricted to registered users. Please log in or create an account.') . '</p>'
                . '</div>';
        }

        return '<div class="rounded-lg border border-indigo-100 dark:border-indigo-500/20 '
            . 'bg-indigo-50/60 dark:bg-slate-800/60 p-6 my-4 text-center">'
            . '<p class="text-sm font-semibold text-slate-900 dark:text-white">'
            . __('This form is for members only.') . '</p>'
            . '<p class="mt-1 text-sm text-slate-600 dark:text-slate-400">'
            . __('Subscribe to get access to submit this form.') . '</p>'
            . '</div>';
    }

    /**
     * Renders the notice shown when a form embedded through the shortcode
     * cannot work because its captcha provider is not configured.
     */
    private function renderFormUnavailableNotice(): string
    {
        return '<div class="rounded-lg border border-amber-200 dark:border-amber-500/20 '
            . 'bg-amber-50 dark:bg-amber-950/30 p-6 my-4 text-center">'
            . '<p class="text-sm font-semibold text-gray-900 dark:text-gray-100">'
            . __('This form is currently unavailable.') . '</p>'
            . '<p class="mt-1 text-sm text-gray-600 dark:text-gray-400">'
            . __('The form could not be displayed because it is not configured correctly. Please contact the site administrator.') . '</p>'
            . '</div>';
    }

    /**
     * Renders the captcha widget for a form embedded through the shortcode.
     * The type comes from Form::getCaptchaType() so the markup always matches
     * the validation FormFrontController performs on submit.
     */
    private function renderEmbeddedCaptcha(Form $form, string $formId, string $type): string
    {
        if ($type === 'none') {
            return '';
        }

        $wrapper = '<div class="flex flex-col items-center justify-center py-2 mt-4 '
            . 'bg-gray-50 dark:bg-gray-800/50 rounded-lg border border-gray-200 '
            . 'dark:border-gray-700 max-w-full overflow-x-auto">';

        if ($type === 'honeypot') {
            $field = htmlspecialchars($form->getHoneypotFieldName(), ENT_QUOTES, 'UTF-8');

            return '<div style="display:none">'
                . '<input type="text" name="' . $field . '" autocomplete="off" tabindex="-1" aria-hidden="true">'
                . '</div>';
        }

        if ($type === 'math') {
            [$a, $b] = [random_int(1, 9), random_int(1, 9)];
            $this->session->set('captcha_answer', $a + $b);

            return $wrapper
                . '<div id="' . $formId . '-captcha" class="w-full text-center">'
                . '<label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">'
                . $a . ' + ' . $b . ' = ? <span class="text-red-500">*</span>'
                . ' <span class="text-xs text-gray-400 font-normal ml-1">(' . __('spam protection') . ')</span>'
                . '</label>'
                . '<input type="number" name="captcha" required class="w-32 rounded-lg border-gray-300 '
                . 'dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm mx-auto">'
                . '</div></div>';
        }

        // Misconfigured providers are rejected by the caller, so a provider
        // always has its site key here.
        $siteKey = $this->formCaptcha->siteKey($form);
        if ($siteKey === null) {
            return '';
        }

        $key = htmlspecialchars($siteKey, ENT_QUOTES, 'UTF-8');

        if ($type === 'hcaptcha') {
            return $wrapper
                . '<div class="h-captcha max-w-full" data-sitekey="' . $key . '"></div>'
                . '<script src="' . htmlspecialchars(CaptchaUrls::fromFile()->scriptUrl('hcaptcha'), ENT_QUOTES, 'UTF-8') . '" async defer></script>'
                . '</div>';
        }

        if ($type === 'turnstile') {
            return $wrapper
                . '<div class="cf-turnstile max-w-full" data-sitekey="' . $key . '" '
                . 'data-theme="auto" data-size="flexible"></div>'
                . '<script src="' . htmlspecialchars(CaptchaUrls::fromFile()->scriptUrl('turnstile'), ENT_QUOTES, 'UTF-8') . '" async defer></script>'
                . '</div>';
        }

        // reCAPTCHA v3: the token is minted client-side and lands in a hidden
        // field, so it is picked up by the FormData the submit handler sends.
        $tokenId = $formId . '-g-recaptcha-response';

        return $wrapper
            . '<input type="hidden" name="g-recaptcha-response" id="' . $tokenId . '">'
            . '<script src="' . htmlspecialchars(CaptchaUrls::fromFile()->scriptUrl('recaptcha'), ENT_QUOTES, 'UTF-8') . '?render=' . $key . '"></script>'
            . '<script>'
            . 'grecaptcha.ready(function(){'
            . 'try{'
            . 'grecaptcha.execute(' . json_encode($siteKey) . ',{action:"form_submission"})'
            . '.then(function(token){document.getElementById(' . json_encode($tokenId) . ').value=token;})'
            . '.catch(function(e){console.warn("reCAPTCHA execute failed:",e);});'
            . '}catch(e){console.warn("reCAPTCHA execute failed:",e);}'
            . '});'
            . '</script>'
            . '</div>';
    }

    private function renderField(array $field): string
    {
        $type     = $field['type'] ?? 'text';
        $label    = htmlspecialchars($field['label'] ?? '', ENT_QUOTES, 'UTF-8');
        $name     = $type === 'email' ? 'email' : ($field['name'] ?? '');
        $name     = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $required = !empty($field['required']);
        $req      = $required ? ' required' : '';
        $star     = $required ? ' <span class="text-red-500">*</span>' : '';

        if ($name === '' && $type !== 'static_text') {
            return '';
        }

        $inputName = 'fields[' . $name . ']';

        // Same responsive column classes as the standalone form page, so a
        // field keeps its configured width in both render paths. The previous
        // inline `flex: 1 1 50%` let a lone partial-width column stretch to
        // fill the whole row via flex-grow.
        $widthClass = match (trim((string) ($field['width'] ?? ''))) {
            '50'    => 'w-full md:w-1/2',
            '33'    => 'w-full md:w-1/3',
            '25'    => 'w-full md:w-1/4',
            default => 'w-full',
        };

        $html = '<div class="vl-field-col ' . $widthClass . ' px-2.5 mb-5">';

        if ($type === 'newsletter_subscribe' || ($type === 'checkbox' && empty($field['options']))) {
            $html .= '<label class="flex items-center gap-2 text-sm">';
            $html .= '<input type="checkbox" name="' . $inputName . '" ';
            $html .= 'value="1" class="rounded border-gray-300"' . $req . '>';
            $html .= ' ' . $label . $star;
            $html .= '</label>';
        } else {
            $html .= '<label class="block text-sm font-medium text-gray-700 mb-1">' . $label . $star . '</label>';

            $placeholder = '';
            if (isset($field['placeholder']) && is_string($field['placeholder'])) {
                $placeholder = trim($field['placeholder']);
            }
            $ph = $placeholder !== ''
                ? ' placeholder="' . htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') . '"'
                : '';

            if (in_array($type, ['text', 'email', 'tel', 'number', 'url'], true)) {
                $html .= '<input type="' . $type . '" name="' . $inputName . '"' . $req . $ph;
                $html .= ' class="w-full rounded-lg border-gray-300 text-sm">';
            } elseif ($type === 'textarea') {
                $html .= '<textarea name="' . $inputName . '"' . $req . $ph;
                $html .= ' rows="4" class="w-full rounded-lg border-gray-300 text-sm"></textarea>';
            } elseif ($type === 'select') {
                $html .= '<select name="' . $inputName . '"' . $req;
                $html .= ' class="w-full rounded-lg border-gray-300 text-sm">';
                $html .= '<option value="">— choose —</option>';
                foreach (self::normalizeFormOptions($field['options'] ?? []) as $option) {
                    $o = htmlspecialchars($option['value'], ENT_QUOTES, 'UTF-8');
                    $t = self::formOptionTitleAttr($option['title']);
                    $html .= '<option value="' . $o . '"' . $t . '>' . $o . '</option>';
                }
                $html .= '</select>';
            } elseif ($type === 'checkbox') {
                $html .= '<div class="space-y-2">';
                foreach (self::normalizeFormOptions($field['options'] ?? []) as $option) {
                    $o = htmlspecialchars($option['value'], ENT_QUOTES, 'UTF-8');
                    $t = self::formOptionTitleAttr($option['title']);
                    $html .= '<label class="flex items-center gap-2 text-sm"' . $t . '>';
                    $html .= '<input type="checkbox" name="' . $inputName . '[]" ';
                    $html .= 'value="' . $o . '" class="rounded border-gray-300"> ' . $o . '</label>';
                }
                $html .= '</div>';
            } elseif ($type === 'radio') {
                $html .= '<div class="space-y-2">';
                foreach (self::normalizeFormOptions($field['options'] ?? []) as $option) {
                    $o = htmlspecialchars($option['value'], ENT_QUOTES, 'UTF-8');
                    $t = self::formOptionTitleAttr($option['title']);
                    $html .= '<label class="flex items-center gap-2 text-sm"' . $t . '>';
                    $html .= '<input type="radio" name="' . $inputName . '" ';
                    $html .= 'value="' . $o . '" class="border-gray-300"> ' . $o . '</label>';
                }
                $html .= '</div>';
            }
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Normalize select/checkbox/radio options into {value, title} pairs.
     * Legacy plain-string options are upgraded in place — the tooltip title
     * is optional and defaults to an empty string.
     *
     * @return array<int, array{value: string, title: string}>
     */
    private static function normalizeFormOptions(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $normalized = [];
        foreach ($raw as $option) {
            if (is_array($option)) {
                $value = is_scalar($option['value'] ?? null) ? trim((string) $option['value']) : '';
                $title = is_scalar($option['title'] ?? null) ? trim((string) $option['title']) : '';
            } elseif (is_scalar($option) || $option === null) {
                $value = trim((string) $option);
                $title = '';
            } else {
                continue;
            }
            if ($value === '') {
                continue;
            }
            $normalized[] = ['value' => $value, 'title' => $title];
        }
        return $normalized;
    }

    /** Escaped HTML title attribute for a form option — empty string when no title is set. */
    private static function formOptionTitleAttr(string $title): string
    {
        $title = trim($title);

        return $title === '' ? '' : ' title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"';
    }

    private function renderNewsletterForm(string $attrsRaw): string
    {
        if ($this->settings->get('newsletter_enabled', '1') === '0') {
            return '';
        }

        $this->hadShortcodes = true;
        $attrs               = $this->parseAttributes($attrsRaw);
        $placeholder         = $attrs['placeholder']  ?? 'Your email address';
        $buttonText          = $attrs['button']       ?? 'Subscribe';
        $privacyPageSlug     = $attrs['privacy_page'] ?? '';
        $tag                 = $attrs['tag']          ?? '';
        $captcha             = strtolower(trim($attrs['captcha'] ?? 'disabled'));
        if ($captcha === 'none' || $captcha === 'off' || $captcha === '0') {
            $captcha = 'disabled';
        }
        // Custom honeypot field name, same pattern as regular forms. Only
        // alphanumerics, underscore and dash survive sanitization.
        $honeypotName = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($attrs['honeypot_name'] ?? ''));
        if ($honeypotName === '') {
            $honeypotName = '_hp_name';
        }
        $width = $this->normalizeWidth($attrs['width'] ?? '');

        // Remember the rendered type server-side: the submit endpoint reads it
        // from the session, never from the request body.
        $this->session->set('newsletter_captcha_type', $captcha);
        $this->session->set('newsletter_honeypot_name', $honeypotName);

        if ($this->formCaptcha->isProviderMisconfigured($captcha)) {
            error_log(sprintf(
                '[Newsletter] Form with tag "%s" is hidden because captcha provider "%s" is not configured.',
                $tag,
                $captcha,
            ));

            return '<div class="rounded-lg border border-amber-200 dark:border-amber-500/20 '
                . 'bg-amber-50 dark:bg-amber-950/30 p-4 my-4 text-center">'
                . '<p class="text-sm font-semibold text-gray-900 dark:text-gray-100">'
                . __('This form will not be shown because captcha is not configured.') . '</p>'
                . '</div>';
        }

        $csrf   = htmlspecialchars($this->csrf->getToken(), ENT_QUOTES, 'UTF-8');
        $ph     = htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8');
        $btn    = htmlspecialchars($buttonText, ENT_QUOTES, 'UTF-8');
        $formId = 'vl-newsletter-' . bin2hex(random_bytes(4));

        $html  = '<div class="vl-newsletter-form mt-0 mb-8" id="' . $formId . '-wrapper"';
        if ($width !== '') {
            $html .= ' style="max-width:' . $width . ';margin-left:auto;margin-right:auto;"';
        }
        $html .= '>';
        if ($width !== '') {
            // A constrained width (e.g. 50%) leaves <300px for the captcha
            // widget on phones, so fall back to full width below sm screens.
            $html .= '<style>#' . $formId . '-wrapper{box-sizing:border-box;}'
                . '@media (max-width:639px){#' . $formId . '-wrapper{max-width:100%!important;}}</style>';
        }
        $html .= '<form method="POST" action="' . $this->basePath . '/newsletter/subscribe" ';
        $html .= 'class="space-y-3" id="' . $formId . '">';
        $html .= '<input type="hidden" name="_csrf_token" value="' . $csrf . '">';
        if ($tag !== '') {
            $html .= '<input type="hidden" name="tag" value="'
                . htmlspecialchars($tag, ENT_QUOTES, 'UTF-8') . '">';
        }

        if ($captcha === 'honeypot') {
            $html .= '<div style="display:none">'
                . '<input type="text" name="' . htmlspecialchars($honeypotName, ENT_QUOTES, 'UTF-8') . '" autocomplete="off" '
                . 'tabindex="-1" aria-hidden="true">'
                . '</div>';
        }

        $html .= '<div class="flex flex-col sm:flex-row gap-3 sm:items-end flex-wrap">';
        $html .= '<div class="flex-1 w-full min-w-[200px]">';
        $html .= '<input type="email" name="email" required placeholder="' . $ph . '" ';
        $html .= 'class="w-full rounded-lg border-gray-300 text-sm">';
        $html .= '</div>';
        $html .= '<button type="submit" class="w-full sm:w-auto rounded-lg btn-primary px-5 py-2.5 ';
        $html .= 'text-sm font-medium text-white hover:opacity-90">' . $btn . '</button>';
        $html .= '</div>';

        // Captcha gets its own centered row below the email + button row.
        if ($captcha === 'math') {
            [$a, $b] = [random_int(1, 9), random_int(1, 9)];
            $this->session->set('newsletter_captcha_answer', $a + $b);

            $html .= '<div class="flex justify-center py-2">';
            $html .= '<div class="text-center">';
            $html .= '<label class="block text-xs font-medium text-gray-700 '
                . 'dark:text-gray-300 mb-1">'
                . $a . ' + ' . $b . ' = ?</label>';
            $html .= '<input type="number" name="captcha_answer" required ';
            $html .= 'placeholder="?" class="w-24 mx-auto ';
            $html .= 'rounded-lg border-gray-300 dark:border-gray-700 '
                . 'dark:bg-gray-800 dark:text-gray-100 text-sm">';
            $html .= '</div>';
            $html .= '</div>';
        } elseif ($captcha === 'hcaptcha') {
            $siteKey   = $this->formCaptcha->siteKeyForProvider('hcaptcha') ?? '';
            $siteKey   = htmlspecialchars($siteKey, ENT_QUOTES, 'UTF-8');
            $scriptUrl = htmlspecialchars(
                CaptchaUrls::fromFile()->scriptUrl('hcaptcha'),
                ENT_QUOTES,
                'UTF-8'
            );
            $html .= '<div class="flex justify-center py-2 max-w-full overflow-x-auto">';
            $html .= '<div class="h-captcha max-w-full" data-sitekey="' . $siteKey . '"></div>';
            $html .= '<script src="' . $scriptUrl . '" async defer></script>';
            $html .= '</div>';
        } elseif ($captcha === 'turnstile') {
            $siteKey   = $this->formCaptcha->siteKeyForProvider('turnstile') ?? '';
            $siteKey   = htmlspecialchars($siteKey, ENT_QUOTES, 'UTF-8');
            $scriptUrl = htmlspecialchars(
                CaptchaUrls::fromFile()->scriptUrl('turnstile'),
                ENT_QUOTES,
                'UTF-8'
            );
            $html .= '<div class="flex justify-center py-2 max-w-full overflow-x-auto">';
            $html .= '<div class="cf-turnstile max-w-full" data-sitekey="' . $siteKey . '" ';
            $html .= 'data-theme="auto" data-size="flexible"></div>';
            $html .= '<script src="' . $scriptUrl . '" async defer></script>';
            $html .= '</div>';
        } elseif ($captcha === 'recaptcha') {
            $siteKey = $this->formCaptcha->siteKeyForProvider('recaptcha') ?? '';
            $tokenId = $formId . '-g-recaptcha-response';
            $key     = htmlspecialchars($siteKey, ENT_QUOTES, 'UTF-8');
            $html .= '<input type="hidden" name="g-recaptcha-response" id="' . $tokenId . '">';
            $html .= '<script src="' . htmlspecialchars(CaptchaUrls::fromFile()->scriptUrl('recaptcha'), ENT_QUOTES, 'UTF-8') . '?render='
                . $key . '"></script>';
            $html .= '<script>';
            $html .= 'grecaptcha.ready(function(){try{';
            $html .= 'grecaptcha.execute(' . json_encode($siteKey)
                . ',{action:"form_submission"})';
            $html .= '.then(function(token){';
            $html .= 'document.getElementById(' . json_encode($tokenId) . ').value=token;})';
            $html .= '.catch(function(e){console.warn("reCAPTCHA execute failed:",e);});';
            $html .= '}catch(e){console.warn("reCAPTCHA execute failed:",e);}});';
            $html .= '</script>';
        }

        if ($privacyPageSlug !== '') {
            $privacyPage = $this->posts->findBySlug(new Slug($privacyPageSlug), 'page');
            if ($privacyPage !== null) {
                $pagePrefix = $this->settings->get('routing_page_prefix', '');
                $path       = $this->basePath . ($pagePrefix ? '/' . ltrim($pagePrefix, '/') : '');
                $fullPath   = $this->posts->buildPagePath($privacyPage);
                $url        = $path . '/' . ltrim($fullPath, '/');

                $html .= '<div class="flex items-start gap-2">';
                $html .= '<input type="checkbox" name="privacy_accepted" id="' . $formId . '-privacy" required class="mt-1 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">';
                $privacyLinkHtml = '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" class="underline hover:text-gray-700">' . __('Privacy Policy') . '</a>';
                $agreementText   = __('I agree to the :link', [':link' => $privacyLinkHtml]);
                $html .= '<label for="' . $formId . '-privacy" class="text-xs text-gray-500 mt-1">' . $agreementText . '</label>';
                $html .= '</div>';
            }
        }

        $html .= '</form>';
        $html .= '<div id="' . $formId . '-message" class="hidden rounded-lg p-4 text-sm mt-3"></div>';
        $html .= '</div>';

        return $html;
    }

    private function processAdShortcode(string $content): string
    {
        $pattern = '/<p[^>]*>\s*(?:<span[^>]*>)?\s*\[ad\]\s*(?:<\/span>)?\s*<\/p>|\[ad\]/i';

        $enabled = $this->settings->get('advertisement_enabled', '0') === '1';
        if (!$enabled) {
            return preg_replace($pattern, '', $content) ?? $content;
        }

        $adCode = $this->settings->get('advertisement_code', '');
        if ($adCode === '') {
            return preg_replace($pattern, '', $content) ?? $content;
        }

        $this->hadShortcodes = true;
        return preg_replace($pattern, $adCode, $content) ?? $content;
    }

    // ── Redirect ────────────────────────────────────────────────────────

    public function resolveInternalUrl(string $type, int $id): ?string
    {
        if ($id <= 0) {
            return null;
        }

        if ($type === 'page') {
            $page = $this->posts->findById($id);
            if ($page === null || $page->getContentType() !== 'page' || !in_array($page->getStatus(), ['published', 'unlisted'], true)) {
                return null;
            }
            $pagePrefix = $this->settings->get('routing_page_prefix', '');
            $prefix     = $pagePrefix ? ('/' . ltrim($pagePrefix, '/')) : '';
            return site_url($prefix . '/' . $this->posts->buildPagePath($page));
        }

        if ($type === 'post') {
            $post = $this->posts->findById($id);
            if ($post === null || $post->getContentType() !== 'post' || !in_array($post->getStatus(), ['published', 'unlisted'], true)) {
                return null;
            }
            $blogPrefix = $this->settings->get('routing_blog_prefix', '/blog');
            return site_url('/' . ltrim($blogPrefix, '/') . '/' . $post->getSlug());
        }

        if ($type === 'product') {
            $product = $this->products->findById($id);
            if ($product === null || !in_array($product->getStatus(), ['published', 'unlisted'], true)) {
                return null;
            }
            $shopPrefix = $this->settings->get('routing_shop_prefix', '/shop');
            return site_url('/' . ltrim($shopPrefix, '/') . '/' . $product->getSlug());
        }

        if ($type === 'home') {
            return site_url('/');
        }

        if ($type === 'shop') {
            $shopPrefix = $this->settings->get('routing_shop_prefix', '/shop');
            return site_url('/' . ltrim($shopPrefix, '/'));
        }

        // Custom content types
        $post = $this->posts->findById($id);
        if ($post === null || $post->getContentType() !== $type || !in_array($post->getStatus(), ['published', 'unlisted'], true)) {
            return null;
        }

        $basePath = '/' . $type;
        if ($this->contentTypes !== null) {
            try {
                $cType = $this->contentTypes->findBySlug(new Slug($type));
                if ($cType !== null) {
                    $basePath = $cType->getPublicBasePath();
                }
            } catch (\Throwable) {
            }
        }

        return site_url('/' . ltrim($basePath, '/') . '/' . $post->getSlug());
    }

    private function processInternalLinks(string $content): string
    {
        if (!str_contains($content, 'data-vl-type') && !str_contains($content, 'data-vl-internal')) {
            return $content;
        }

        return preg_replace_callback(
            '/<a\b([^>]*)>(.*?)<\/a>/is',
            function (array $m): string {
                $attrsHtml = $m[1];
                $innerHtml = $m[2];

                $type = null;
                $id   = null;

                if (preg_match('/data-vl-type=["\']([^"\']+)["\']/', $attrsHtml, $tm)) {
                    $type = $tm[1];
                }
                if (preg_match('/data-vl-id=["\']([^"\']+)["\']/', $attrsHtml, $im)) {
                    $id = (int) $im[1];
                }

                if (($type === null || $id === null) && preg_match('/data-vl-internal=["\']([^:"\']+):(\d+)["\']/', $attrsHtml, $inm)) {
                    $type = $inm[1];
                    $id   = (int) $inm[2];
                }

                if ($type === null || $id === null || $id <= 0) {
                    return $m[0];
                }

                $resolvedUrl = $this->resolveInternalUrl($type, $id);

                if ($resolvedUrl === null) {
                    // Target entity deleted or unpublished -> strip <a> tag, render inner content
                    return $innerHtml;
                }

                $escapedUrl = htmlspecialchars($resolvedUrl, ENT_QUOTES, 'UTF-8');
                if (preg_match('/\bhref=["\'].*?["\']/i', $attrsHtml)) {
                    $newAttrs = preg_replace('/\bhref=["\'].*?["\']/i', 'href="' . $escapedUrl . '"', $attrsHtml);
                } else {
                    $newAttrs = 'href="' . $escapedUrl . '" ' . $attrsHtml;
                }

                return '<a' . $newAttrs . '>' . $innerHtml . '</a>';
            },
            $content
        ) ?? $content;
    }

    private function processRedirect(string $content): string
    {
        // [redirect url="..." seconds="..."] — strip wrapping <p> tag if present
        $content = preg_replace_callback(
            '/<p>\s*\[redirect(?:\s+([^\]]*))?\]\s*<\/p>/',
            fn ($m) => $this->renderRedirect($this->decodeAttrs($m[1] ?? '')),
            $content,
        );
        // Fallback: shortcode not wrapped in <p>
        $content = preg_replace_callback(
            '/\[redirect(?:\s+([^\]]*))?\]/',
            fn ($m) => $this->renderRedirect($this->decodeAttrs($m[1] ?? '')),
            $content,
        );

        return $content;
    }

    private function renderRedirect(string $rawAttrs): string
    {
        $attrs = $this->parseAttributes($rawAttrs);
        $url   = trim($attrs['url'] ?? '');

        $type = $attrs['internal_type'] ?? ($attrs['data-vl-type'] ?? null);
        $id   = isset($attrs['internal_id']) ? (int) $attrs['internal_id'] : (isset($attrs['data-vl-id']) ? (int) $attrs['data-vl-id'] : 0);

        if ($type !== null && $id > 0) {
            $resolved = $this->resolveInternalUrl($type, $id);
            if ($resolved === null) {
                return ''; // Target entity deleted or unpublished -> omit redirect
            }
            $url = $resolved;
        }

        if ($url === '') {
            return '';
        }

        // Reject dangerous schemes; accept http(s), protocol-relative and site paths.
        if (preg_match('#^([a-z0-9+.-]+):#i', $url, $scheme) === 1) {
            if (!in_array(strtolower($scheme[1]), ['http', 'https'], true)) {
                return '';
            }
        } elseif (!str_starts_with($url, '/')) {
            $url = '/' . ltrim($url, '/');
        }

        $seconds = isset($attrs['seconds']) ? (int) $attrs['seconds'] : 5;
        $seconds = max(0, min(300, $seconds));

        if ($seconds === 0) {
            $targetUrl = (preg_match('#^https?://#i', $url) === 1) ? $url : site_url($url);
            throw new Redirect302Exception($targetUrl);
        }

        $this->hadShortcodes = true;

        $href = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $html = '<div class="vl-redirect-box" data-vl-redirect>';
        if ($seconds > 0) {
            $count = '<strong data-vl-redirect-count>' . $seconds . '</strong>';
            $html .= '<p>' . __('You are being redirected in :count seconds…', [':count' => $count]) . '</p>';
        } else {
            $html .= '<p>' . __('You are being redirected…') . '</p>';
        }
        $link = __('Click here if you are not redirected automatically.');
        $html .= '<p><a href="' . $href . '">' . $link . '</a></p>';
        $html .= '</div>';

        // Only the first redirect on a page drives the navigation; extras
        // render as informational boxes with a manual link.
        if (!$this->redirectRendered) {
            $this->redirectRendered = true;
            $html .= '<script>(function(){var u=' . json_encode($url, JSON_UNESCAPED_SLASHES) . ',s=' . $seconds . ';';
            $html .= 'function go(){window.location.replace(u);}';
            if ($seconds > 0) {
                // Every box shows its own countdown — update all of them.
                $html .= 'var l=s,e=document.querySelectorAll("[data-vl-redirect-count]");';
                $html .= 'var t=setInterval(function(){l--;';
                $html .= 'e.forEach(function(n){n.textContent=l;});';
                $html .= 'if(l<=0){clearInterval(t);go();}},1000);';
            } else {
                $html .= 'go();';
            }
            $html .= '})();</script>';
            $html .= '<noscript><meta http-equiv="refresh" content="' . $seconds . ';url=' . $href . '"></noscript>';
        }

        return $html;
    }

    private function renderFieldShortcode(string $attrs): string
    {
        if (!$this->currentPost) {
            return '';
        }
        if (preg_match('/name=["\']([^"\']+)["\']/', $attrs, $m)) {
            $this->hadShortcodes = true;
            return htmlspecialchars((string) $this->currentPost->getMeta($m[1]), ENT_QUOTES, 'UTF-8');
        }
        return '';
    }

    // ── Product Embed ─────────────────────────────────────────────────────

    private function processProduct(string $content): string
    {
        return preg_replace_callback(
            '/<p>\s*\[product\s+([^\]]+)\]\s*<\/p>|\[product\s+([^\]]+)\]/',
            function ($matches) {
                $attrsRaw = !empty($matches[1]) ? $matches[1] : (!empty($matches[2]) ? $matches[2] : '');
                if ($attrsRaw === '') {
                    return $matches[0];
                }
                $attrs = $this->parseAttributes($attrsRaw);

                $id = (int) ($attrs['id'] ?? 0);
                if ($id <= 0) {
                    return $matches[0];
                }

                $variationId     = (int) ($attrs['variation_id'] ?? 0);
                $showImage       = ($attrs['show_image'] ?? 'true')       === 'true';
                $showPrice       = ($attrs['show_price'] ?? 'true')       === 'true';
                $showDescription = ($attrs['show_description'] ?? 'true') === 'true';
                $bgColor         = $attrs['bg'] ?? '';

                // Prices are stored in the store default currency. The embed is
                // baked into (possibly cached) page HTML, so it must not apply
                // the visitor's fx conversion — only the store currency label.
                $currency = strtoupper((string) ($this->settings->get('currency', 'USD') ?? 'USD'));
                $currency = preg_replace('/[^A-Z]/', '', $currency);
                if ($currency === '' || $currency === null) {
                    $currency = 'USD';
                }

                $product = $this->products->findById($id);

                // If the current post has a language, try to resolve the product
                // translation for that language
                $postLanguage = $this->currentPost?->getLanguage();

                if ($product === null) {
                    return $this->renderProductHtml(
                        id: $id,
                        name: 'Product',
                        price: '',
                        imgUrl: '',
                        slug: '',
                        description: '',
                        isAvailable: false,
                        showImage: $showImage,
                        showPrice: $showPrice,
                        showDescription: $showDescription,
                        bgColor: $bgColor,
                        language: $postLanguage,
                        currency: $currency,
                        variationAttributes: null,
                    );
                }

                if ($postLanguage !== null && $postLanguage !== '') {
                    $originalId   = $product->getTranslationOf() ?? $product->getId();
                    $translations = $this->products->findTranslations($originalId);
                    foreach ($translations as $t) {
                        if ($t->getLanguage() === $postLanguage && $t->getId() !== $product->getId()) {
                            $product = $t;
                            break;
                        }
                    }
                }

                $this->hadShortcodes = true;

                $isAvailable = in_array($product->getStatus(), ['published', 'unlisted'], true)
                    && !$product->isExpired();

                $variations = $this->products->findVariationsByProductId($product->getId());

                $displayName         = $product->getName();
                $imgId               = $product->getFeaturedImageId();
                $variationAttributes = null;

                if ($variationId > 0) {
                    $row = $this->findVariationRow($variations, $variationId);
                } else {
                    $row = null;
                }

                if ($row !== null) {
                    $effective     = VariationPrice::resolve($row);
                    $original      = (float) ($row['price'] ?? 0.0);
                    $price         = number_format($effective, 2);
                    $originalPrice = ($original > $effective) ? number_format($original, 2) : null;
                    $label         = $this->variationLabel($row);
                    if ($label !== '') {
                        $displayName .= ' — ' . $label;
                    }
                    if ((string) ($row['stock_status'] ?? 'in_stock') === 'out_of_stock') {
                        $isAvailable = false;
                    }
                    if (!empty($row['image_id'])) {
                        $imgId = (int) $row['image_id'];
                    }
                    // Build variation attributes for the link
                    $attrs = $row['attributes'] ?? [];
                    if (is_string($attrs)) {
                        $decoded = json_decode($attrs, true);
                        $attrs   = is_array($decoded) ? $decoded : [];
                    }
                    if (is_array($attrs) && $attrs !== []) {
                        $variationAttributes = $attrs;
                    }
                } elseif ($variations !== []) {
                    $range = $this->variationPriceRange($variations);
                    $price = number_format($range['min'], 2) === number_format($range['max'], 2)
                        ? number_format($range['min'], 2)
                        : number_format($range['min'], 2) . ' – ' . number_format($range['max'], 2);
                    $originalPrice = null;
                } else {
                    $price         = number_format($product->getEffectivePrice(), 2);
                    $originalPrice = ($product->isSaleActive() && $product->getSalePrice() !== null)
                        ? number_format($product->getPrice(), 2)
                        : null;
                }

                $imgUrl = null;
                if ($imgId) {
                    $media = $this->media->findById($imgId);
                    if ($media) {
                        $imgUrl = (string) ($media->getThumbnailUrl('webp', site_url('/uploads'))
                            ?? $media->getUrl(site_url('/uploads')));
                    }
                }

                return $this->renderProductHtml(
                    id: $product->getId(),
                    name: htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'),
                    price: $price,
                    originalPrice: $originalPrice,
                    imgUrl: htmlspecialchars((string) $imgUrl, ENT_QUOTES, 'UTF-8'),
                    slug: (string) $product->getSlug(),
                    description: htmlspecialchars((string) $product->getShortDescription(), ENT_QUOTES, 'UTF-8'),
                    isAvailable: $isAvailable,
                    showImage: $showImage,
                    showPrice: $showPrice,
                    showDescription: $showDescription,
                    bgColor: $bgColor,
                    language: $postLanguage,
                    currency: $currency,
                    variationAttributes: $variationAttributes,
                );
            },
            $content,
        );
    }

    private function renderProductHtml(
        int $id,
        string $name,
        string $price,
        string $imgUrl,
        string $slug,
        string $description,
        bool $isAvailable,
        bool $showImage,
        bool $showPrice,
        bool $showDescription,
        string $bgColor = '',
        ?string $language = null,
        ?string $originalPrice = null,
        string $currency = 'USD',
        ?array $variationAttributes = null,
    ): string {
        $shopPrefix  = $this->settings->get('routing_shop_prefix', '/shop');
        $path        = $shopPrefix . '/' . ($slug !== '' ? rawurlencode($slug) : $id);
        $queryParams = [];
        if ($language !== null && $language !== '') {
            $queryParams['lang'] = $language;
        }
        if ($variationAttributes !== null) {
            foreach ($variationAttributes as $k => $v) {
                if ($v !== '' && $v !== null) {
                    $queryParams[$k] = $v;
                }
            }
        }
        $link = site_url($path, [], $queryParams);

        // Theme-aware defaults (mirrors carousel pattern)
        $theme = $this->settings->get('active_theme', 'default');
        switch ($theme) {
            case 'dark':
                $defaultBg        = '#1f2937';
                $imgPlaceholderBg = '#374151';
                $borderColor      = '#374151';
                $nameColor        = '#f3f4f6';
                $descColor        = '#9ca3af';
                break;
            case 'vivid':
                $defaultBg        = '#f3f4f6';
                $imgPlaceholderBg = '#e5e7eb';
                $borderColor      = '#d1d5db';
                $nameColor        = '#111827';
                $descColor        = '#6b7280';
                break;
            default:
                $defaultBg        = '#ffffff';
                $imgPlaceholderBg = '#f3f4f6';
                $borderColor      = '#e5e7eb';
                $nameColor        = '#111827';
                $descColor        = '#6b7280';
                break;
        }

        $finalBg      = $bgColor !== '' ? $bgColor : $defaultBg;
        $imgBg        = $bgColor === 'transparent' ? 'transparent' : $imgPlaceholderBg;

        $html = '<a href="' . $link . '" class="vl-product-embed" style="display:flex;gap:1rem;padding:1rem;border:1px solid ' . $borderColor . ';border-radius:0.5rem;text-decoration:none;color:inherit;margin:1rem 0;background:' . $finalBg . ';transition:box-shadow 0.2s" onmouseover="this.style.boxShadow=\'0 4px 12px rgba(0,0,0,0.08)\'" onmouseout="this.style.boxShadow=\'none\'">';

        if ($showImage && $imgUrl !== '') {
            $html .= '<div style="flex-shrink:0;width:120px;height:120px;border-radius:0.375rem;overflow:hidden;background:' . $imgBg . '">';
            $html .= '<img src="' . $imgUrl . '" alt="' . $name . '" style="width:100%;height:100%;object-fit:cover">';
            $html .= '</div>';
        }

        $html .= '<div style="flex:1;min-width:0">';
        $html .= '<div style="font-weight:600;font-size:1.05rem;margin-bottom:0.25rem;color:' . $nameColor . '">' . $name . '</div>';

        if ($showPrice) {
            $suffix = ' ' . $currency;
            if ($originalPrice !== null && $originalPrice !== $price) {
                $html .= '<div style="margin-bottom:0.25rem">'
                    . '<span style="font-weight:700;color:#dc2626">' . $price . $suffix . '</span> '
                    . '<span style="color:#9ca3af;text-decoration:line-through">'
                    . $originalPrice . $suffix . '</span></div>';
            } else {
                $html .= '<div style="font-weight:700;color:#059669;margin-bottom:0.25rem">'
                    . $price . $suffix . '</div>';
            }
        }

        if ($showDescription && $description !== '') {
            $html .= '<div style="color:' . $descColor . ';font-size:0.875rem;line-height:1.4">' . $description . '</div>';
        }

        if (!$isAvailable) {
            $html .= '<div style="color:#ef4444;font-size:0.8rem;margin-top:0.25rem;font-style:italic">Product is no longer available.</div>';
        }

        $html .= '</div>';
        $html .= '</a>';

        return $html;
    }

    /**
     * @param array<int, array<string, mixed>> $variations raw vl_product_variations rows
     * @return array{min: float, max: float}
     */
    private function variationPriceRange(array $variations): array
    {
        $prices = [];
        foreach ($variations as $row) {
            $resolved = VariationPrice::resolve($row);
            if ($resolved > 0.0) {
                $prices[] = $resolved;
            }
        }
        if ($prices === []) {
            return ['min' => 0.0, 'max' => 0.0];
        }

        return ['min' => min($prices), 'max' => max($prices)];
    }

    /**
     * @param array<int, array<string, mixed>> $variations
     * @return array<string, mixed>|null
     */
    private function findVariationRow(array $variations, int $variationId): ?array
    {
        foreach ($variations as $row) {
            if ((int) ($row['id'] ?? 0) === $variationId) {
                return $row;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $row */
    private function variationLabel(array $row): string
    {
        $attrs = $row['attributes'] ?? [];
        if (is_string($attrs)) {
            $decoded = json_decode($attrs, true);
            $attrs   = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($attrs) || $attrs === []) {
            return '';
        }

        return implode(
            ' / ',
            array_map(static fn ($v): string => (string) $v, array_values($attrs))
        );
    }

    private function isVideo(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($ext, ['mp4', 'webm', 'ogg', 'mov', 'm4v'])) {
            return true;
        }

        if (str_contains($url, 'youtube.com') || str_contains($url, 'youtu.be') || str_contains($url, 'vimeo.com')) {
            return true;
        }

        return false;
    }

    /**
     * Wrap bare <iframe> tags in the responsive .vl-video-embed container.
     *
     * Already-wrapped embeds (inside .vl-video-embed) are left untouched via
     * placeholder protection so their inner absolute-positioned iframe keeps
     * working. Anything else — typically a pasted YouTube iframe carrying
     * style="position:absolute;...width:100%;height:100%" — would otherwise
     * position itself against the page and cover the whole screen.
     */
    private function wrapBareVideoIframes(string $content): string
    {
        $protected = [];
        $content   = preg_replace_callback(
            '/<div[^>]*class="[^"]*vl-video-embed[^"]*"[^>]*>.*?<\/div>/is',
            static function (array $m) use (&$protected): string {
                $key             = "\x00VLBVID" . count($protected) . "\x00";
                $protected[$key] = $m[0];

                return $key;
            },
            $content,
        ) ?? $content;

        $content = preg_replace_callback(
            '/(?:<p[^>]*>\s*)?(<iframe\b[^>]*>.*?<\/iframe>)(?:\s*<\/p>)?/is',
            static function (array $m): string {
                $iframe = $m[1];
                if (!preg_match('/\bsrc=(["\'])(.*?)\1/i', $iframe, $sm)) {
                    return $m[0];
                }
                $src = trim(html_entity_decode($sm[2], ENT_QUOTES, 'UTF-8'));
                if (preg_match('#^https?://[^"\s<>]+$#i', $src) !== 1) {
                    return $m[0];
                }
                $safeSrc = htmlspecialchars($src, ENT_QUOTES, 'UTF-8');

                return '<div class="vl-video-embed" style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;max-width:100%;margin:1.5rem 0">'
                    . '<iframe src="' . $safeSrc . '" style="position:absolute;top:0;left:0;width:100%;height:100%;border:0" '
                    . 'allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen loading="lazy"></iframe>'
                    . '</div>';
            },
            $content,
        ) ?? $content;

        if ($protected !== []) {
            $content = strtr($content, $protected);
        }

        return $content;
    }

    /**
     * Render a WordPress-style [embed]URL[/embed] shortcode as a responsive
     * video iframe (YouTube/Vimeo) or a plain link for anything else.
     */
    private function renderEmbed(string $url): string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES, 'UTF-8'));
        if ($url === '') {
            return '';
        }

        $embedUrl = null;
        if (preg_match('/(?:youtube\.com\/(?:watch\?v=|embed\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $url, $m)) {
            $embedUrl = $this->embedBase('youtube') . $m[1];
        } elseif (preg_match('/vimeo\.com\/(\d+)/', $url, $m)) {
            $embedUrl = $this->embedBase('vimeo') . $m[1];
        }

        if ($embedUrl === null) {
            $safe = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            return '<p><a href="' . $safe . '">' . $safe . '</a></p>';
        }

        return '<div class="vl-video-embed" style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;max-width:100%;margin:1.5rem 0">'
            . '<iframe src="' . $embedUrl . '" style="position:absolute;top:0;left:0;width:100%;height:100%;border:0" '
            . 'allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>'
            . '</div>';
    }

    private function renderVideoSlide(string $url, string $fit, bool $autoplay): string
    {
        $ytMatch = [];
        $viMatch = [];

        if (preg_match('/(?:youtube\.com\/(?:watch\?v=|embed\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $url, $ytMatch)) {
            $id       = htmlspecialchars($ytMatch[1], ENT_QUOTES, 'UTF-8');
            $ytBase   = $this->embedBase('youtube');
            $embedUrl = "{$ytBase}{$id}?autoplay=" . ($autoplay ? "1" : "0") . "&mute=1";
            return '<iframe src="' . $embedUrl . '" style="width:100%;height:100%;border:none" allow="autoplay; fullscreen" allowfullscreen></iframe>';
        }

        if (preg_match('/vimeo\.com\/(\d+)/', $url, $viMatch)) {
            $id       = htmlspecialchars($viMatch[1], ENT_QUOTES, 'UTF-8');
            $viBase   = $this->embedBase('vimeo');
            $embedUrl = "{$viBase}{$id}?autoplay=" . ($autoplay ? "1" : "0") . "&muted=1";
            return '<iframe src="' . $embedUrl . '" style="width:100%;height:100%;border:none" allow="autoplay; fullscreen" allowfullscreen></iframe>';
        }

        // Local video
        $attr = 'style="width:100%;height:100%;object-fit:' . $fit . '"';
        if ($autoplay) {
            $attr .= ' autoplay muted';
        }
        $attr .= ' @ended="if(autoplay) next()"';

        return '<video ' . $attr . ' playsinline><source src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></video>';
    }

    /** @return array<string, array<string, string>> */
    private function mediaEmbeds(): array
    {
        if ($this->mediaEmbedsCatalog === null) {
            $data                     = require dirname(__DIR__, 3) . '/config/reference-data.php';
            $this->mediaEmbedsCatalog = is_array($data['media_embeds'] ?? null) ? $data['media_embeds'] : [];
        }

        return $this->mediaEmbedsCatalog;
    }

    /**
     * Embed URL prefix for a provider (e.g. 'youtube', 'vimeo') from the
     * media_embeds catalog. Fails fast on a missing entry so reference-data.php
     * stays the single source of truth.
     */
    private function embedBase(string $provider): string
    {
        $url = $this->mediaEmbeds()[$provider]['embed_url'] ?? '';
        if (!is_string($url) || $url === '') {
            throw new \RuntimeException(
                "Missing embed_url for '{$provider}' in config/reference-data.php (media_embeds)."
            );
        }

        return $url;
    }

    private function processLinkButton(string $content): string
    {
        return preg_replace_callback(
            '/<p>\s*\[link_button(?:\s+([^\]]*))?\](.*?)\[\/link_button\]\s*<\/p>|\[link_button(?:\s+([^\]]*))?\](.*?)\[\/link_button\]/s',
            function ($matches) {
                // If matched with wrapping <p>, $matches[1] & $matches[2] are used. Otherwise $matches[3] & $matches[4].
                $attrsRaw  = !empty($matches[1]) ? $matches[1] : (!empty($matches[3]) ? $matches[3] : '');
                $innerHtml = !empty($matches[2]) ? $matches[2] : (!empty($matches[4]) ? $matches[4] : '');

                $attrs = $this->parseAttributes($attrsRaw);

                $type = $attrs['internal_type'] ?? ($attrs['data-vl-type'] ?? null);
                $id   = isset($attrs['internal_id']) ? (int) $attrs['internal_id'] : (isset($attrs['data-vl-id']) ? (int) $attrs['data-vl-id'] : 0);

                $href = $attrs['href'] ?? '';
                if ($type !== null && $id > 0) {
                    $resolved = $this->resolveInternalUrl($type, $id);
                    if ($resolved === null) {
                        return '';
                    }
                    $href = $resolved;
                }

                $target       = $attrs['target']       ?? '_self';
                $bgColor      = $attrs['bgColor']      ?? '#000000';
                $textColor    = $attrs['textColor']    ?? '#ffffff';
                $borderWidth  = $attrs['borderWidth']  ?? '0px';
                $borderStyle  = $attrs['borderStyle']  ?? 'none';
                $borderColor  = $attrs['borderColor']  ?? '#000000';
                $borderRadius = $attrs['borderRadius'] ?? '4px';
                $padding      = $attrs['padding']      ?? '10px 20px';
                $alignment    = $attrs['alignment']    ?? 'center';
                $fullWidth    = $attrs['fullWidth']    ?? 'false';

                $outerStyle = 'display: flex; width: 100%; margin: 16px 0;';
                if ($fullWidth === 'true') {
                    $outerStyle .= ' justify-content: stretch;';
                } else {
                    if ($alignment === 'left') {
                        $outerStyle .= ' justify-content: flex-start;';
                    } elseif ($alignment === 'right') {
                        $outerStyle .= ' justify-content: flex-end;';
                    } else {
                        $outerStyle .= ' justify-content: center;';
                    }
                }

                $btnStyle = 'display: ' . ($fullWidth === 'true' ? 'block' : 'inline-block') . ';';
                if ($fullWidth === 'true') {
                    $btnStyle .= ' width: 100%;';
                }
                $btnStyle .= ' background-color: ' . htmlspecialchars($bgColor, ENT_QUOTES, 'UTF-8') . ';';
                $btnStyle .= ' color: ' . htmlspecialchars($textColor, ENT_QUOTES, 'UTF-8') . ';';
                $btnStyle .= ' border: ' . htmlspecialchars($borderWidth, ENT_QUOTES, 'UTF-8') . ' ' . htmlspecialchars($borderStyle, ENT_QUOTES, 'UTF-8') . ' ' . htmlspecialchars($borderColor, ENT_QUOTES, 'UTF-8') . ';';
                $btnStyle .= ' border-radius: ' . htmlspecialchars($borderRadius, ENT_QUOTES, 'UTF-8') . ';';
                $btnStyle .= ' padding: ' . htmlspecialchars($padding, ENT_QUOTES, 'UTF-8') . ';';
                $btnStyle .= ' text-align: center; text-decoration: none; font-weight: 500; font-size: 14px;';

                $hrefAttr   = $href   !== '' ? ' href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"' : '';
                $targetAttr = $target !== '_self' ? ' target="' . htmlspecialchars($target, ENT_QUOTES, 'UTF-8') . '"' : '';

                $html = '<div class="vl-link-button-wrapper" style="' . $outerStyle . '">';
                $html .= '<a class="vl-link-button"' . $hrefAttr . $targetAttr . ' style="' . $btnStyle . '">';
                $html .= preg_replace('/<a\b[^>]*>(.*?)<\/a>/is', '$1', $innerHtml);
                $html .= '</a>';
                $html .= '</div>';

                $this->hadShortcodes = true;
                return $html;
            },
            $content
        );
    }

    private function processLinkButtonGroup(string $content): string
    {
        return preg_replace_callback(
            '/<p>\s*\[link_button_group(?:\s+([^\]]*))?\]([\s\S]*?)\[\/link_button_group\]\s*<\/p>|\[link_button_group(?:\s+([^\]]*))?\]([\s\S]*?)\[\/link_button_group\]/s',
            function ($matches) {
                $gattrs = !empty($matches[1]) ? $matches[1] : (!empty($matches[3]) ? $matches[3] : '');
                $inner  = !empty($matches[2]) ? $matches[2] : (!empty($matches[4]) ? $matches[4] : '');

                $attrs  = $this->parseAttributes($gattrs);
                $layout = $attrs['layout'] ?? 'vertical';
                $gap    = (int) ($attrs['gap'] ?? 12);

                $buttonsHtml = preg_replace_callback(
                    '/\[link_button(?:\s+([^\]]*))?\](.*?)\[\/link_button\]/s',
                    function ($m) use ($layout) {
                        $ba   = $this->parseAttributes($m[1] ?? '');
                        $body = $m[2] ?? '';

                        $type = $ba['internal_type'] ?? ($ba['data-vl-type'] ?? null);
                        $id   = isset($ba['internal_id']) ? (int) $ba['internal_id'] : (isset($ba['data-vl-id']) ? (int) $ba['data-vl-id'] : 0);

                        $href = $ba['href'] ?? '';
                        if ($type !== null && $id > 0) {
                            $resolved = $this->resolveInternalUrl($type, $id);
                            if ($resolved === null) {
                                return '';
                            }
                            $href = $resolved;
                        }

                        $target       = $ba['target']       ?? '_self';
                        $bgColor      = $ba['bgColor']      ?? '#000000';
                        $textColor    = $ba['textColor']    ?? '#ffffff';
                        $borderWidth  = $ba['borderWidth']  ?? '0px';
                        $borderStyle  = $ba['borderStyle']  ?? 'none';
                        $borderColor  = $ba['borderColor']  ?? '#000000';
                        $borderRadius = $ba['borderRadius'] ?? '4px';
                        $padding      = $ba['padding']      ?? '10px 20px';
                        $alignment    = $ba['alignment']    ?? 'center';
                        $fullWidth    = $ba['fullWidth']    ?? 'false';

                        $btnStyle = 'display: ' . ($fullWidth === 'true' ? 'block' : 'inline-block') . ';';
                        if ($fullWidth === 'true') {
                            $btnStyle .= ' width: 100%;';
                        }
                        $btnStyle .= ' background-color: ' . htmlspecialchars($bgColor, ENT_QUOTES, 'UTF-8') . ';';
                        $btnStyle .= ' color: ' . htmlspecialchars($textColor, ENT_QUOTES, 'UTF-8') . ';';
                        $btnStyle .= ' border: ' . htmlspecialchars($borderWidth, ENT_QUOTES, 'UTF-8') . ' ' . htmlspecialchars($borderStyle, ENT_QUOTES, 'UTF-8') . ' ' . htmlspecialchars($borderColor, ENT_QUOTES, 'UTF-8') . ';';
                        $btnStyle .= ' border-radius: ' . htmlspecialchars($borderRadius, ENT_QUOTES, 'UTF-8') . ';';
                        $btnStyle .= ' padding: ' . htmlspecialchars($padding, ENT_QUOTES, 'UTF-8') . ';';
                        $btnStyle .= ' text-align: center; text-decoration: none; font-weight: 500; font-size: 14px;';

                        $hrefAttr   = $href   !== '' ? ' href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"' : '';
                        $targetAttr = $target !== '_self' ? ' target="' . htmlspecialchars($target, ENT_QUOTES, 'UTF-8') . '"' : '';

                        $text = preg_replace('/<a\b[^>]*>(.*?)<\/a>/is', '$1', $body);
                        $btn  = '<a class="vl-link-button"' . $hrefAttr . $targetAttr . ' style="' . $btnStyle . '">' . $text . '</a>';

                        // Wrap every button in an alignment row. In a vertical group the
                        // row spans the full container width; in a horizontal group the
                        // buttons are split into equal cells so each can be aligned to
                        // its own side.
                        $justify  = $alignment === 'left' ? 'flex-start' : ($alignment === 'right' ? 'flex-end' : 'center');
                        $rowStyle = $layout    === 'horizontal'
                            ? 'display:flex;flex:1 1 0;min-width:0;justify-content:' . $justify . ';'
                            : 'display:flex;width:100%;justify-content:' . $justify . ';';

                        return '<div class="vl-link-button-row" style="' . $rowStyle . '">' . $btn . '</div>';
                    },
                    $inner
                );

                $flex = $layout === 'horizontal'
                    ? 'flex-direction: row; flex-wrap: wrap; align-items: center;'
                    : 'flex-direction: column; align-items: stretch;';

                $this->hadShortcodes = true;
                return '<div class="vl-link-button-group" style="display: flex; ' . $flex . ' gap: ' . $gap . 'px; margin: 16px 0;">'
                    . $buttonsHtml
                    . '</div>';
            },
            $content
        );
    }

    /**
     * Validate a hex color from block settings. Returns the safe value or ''.
     */
    private function normalizeHexColor(string $raw): string
    {
        $color = strtolower(trim($raw));

        if ($color === '' || $color === 'transparent') {
            return '';
        }

        if (preg_match('/^#[0-9a-f]{3}([0-9a-f]{3})?([0-9a-f]{2})?$/', $color) !== 1) {
            return '';
        }

        return $color;
    }

    /**
     * Validate a URL from block settings. Only relative paths and http(s)
     * URLs are allowed; everything else (javascript:, data:, …) is dropped.
     */
    private function normalizeBlockUrl(string $raw): string
    {
        $url = trim($raw);

        if ($url === '') {
            return '';
        }

        if (preg_match('~^[a-z0-9.-]+\.[a-z]{2,}(?::\d+)?(?:[/?#][^"\s<>]*)?$~i', $url) === 1) {
            $url = 'https://' . $url;
        }

        if (preg_match('#^(https?://[^"\s<>]+|/[^"\s<>]*)$#i', $url) !== 1) {
            return '';
        }

        return $url;
    }

    private function toEmbedUrl(string $url, bool $autoplay = false): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (str_contains($url, 'youtube.com') || str_contains($url, 'youtu.be')) {
            if (preg_match('~(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/embed/)([A-Za-z0-9_-]{11})~', $url, $m) === 1) {
                $src = 'https://www.youtube.com/embed/' . $m[1];
                if ($autoplay) {
                    $src .= '?autoplay=1&mute=1';
                }
                return $src;
            }
        }
        if (str_contains($url, 'vimeo.com')) {
            if (preg_match('~vimeo\.com/(?:video/)?(\d+)~', $url, $m) === 1) {
                $src = 'https://player.vimeo.com/video/' . $m[1];
                if ($autoplay) {
                    $src .= '?autoplay=1&muted=1';
                }
                return $src;
            }
        }
        return $url;
    }

    /**
     * Allow only inline markup inside card title/desc; strip block-level tags
     * so a card can never break the surrounding grid layout.
     */
    private function sanitizeCardText(string $html): string
    {
        $clean = strip_tags($html, '<strong><em><b><i><u><s><code><br><span><a>');

        return trim($clean);
    }

    private function processCardGroup(string $content): string
    {
        $groupPattern = '/<p>\s*\[card_group(?:\s+([^\]]*))?\]'
            . '([\s\S]*?)\[\/card_group\]\s*<\/p>'
            . '|\[card_group(?:\s+([^\]]*))?\]'
            . '([\s\S]*?)\[\/card_group\]/s';
        $itemPattern = '/\[card_item(?:\s+([^\]]*))?\]'
            . '[\s\S]*?\[card_title\]([\s\S]*?)\[\/card_title\]'
            . '[\s\S]*?\[card_desc\]([\s\S]*?)\[\/card_desc\]'
            . '[\s\S]*?\[\/card_item\]/s';

        return preg_replace_callback(
            $groupPattern,
            function ($matches) use ($itemPattern) {
                $gattrs = !empty($matches[1]) ? $matches[1] : ($matches[3] ?? '');
                $inner  = !empty($matches[2]) ? $matches[2] : ($matches[4] ?? '');

                $attrs  = $this->parseAttributes($gattrs);
                $gap    = min(64, max(0, (int) ($attrs['gap'] ?? 12)));
                $bg     = $this->normalizeHexColor($attrs['bg'] ?? '');
                $radius = $attrs['radius'] ?? '12px';
                if (!in_array($radius, ['0', '4px', '8px', '12px', '16px', '24px'], true)) {
                    $radius = '12px';
                }
                $align = $attrs['align'] ?? 'center';
                if (!in_array($align, ['left', 'center', 'right'], true)) {
                    $align = 'center';
                }
                $halign = $attrs['halign'] ?? 'center';
                if (!in_array($halign, ['left', 'center', 'right'], true)) {
                    $halign = 'center';
                }
                $width            = $this->normalizeWidth($attrs['width'] ?? '');
                $lightbox         = ($attrs['lightbox'] ?? 'false') === 'true';
                $hasLightboxImage = false;

                // If no card in the group has an image, the invisible image
                // spacers serve no alignment purpose — drop them entirely so
                // imageless groups don't render a tall empty slot.
                $groupHasImage = false;
                if (preg_match_all($itemPattern, $inner, $itemMatches) > 0) {
                    foreach ($itemMatches[1] as $itemAttrs) {
                        $itemBa = $this->parseAttributes($itemAttrs ?? '');
                        if ($this->normalizeBlockUrl(
                            html_entity_decode($itemBa['image'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8')
                        ) !== '') {
                            $groupHasImage = true;
                            break;
                        }
                    }
                }

                $cardsHtml = preg_replace_callback(
                    $itemPattern,
                    function ($m) use ($bg, $radius, $align, $lightbox, $groupHasImage, &$hasLightboxImage) {
                        $ba    = $this->parseAttributes($m[1] ?? '');
                        $image = $this->normalizeBlockUrl(
                            html_entity_decode($ba['image'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8')
                        );
                        $link = $this->normalizeBlockUrl(
                            html_entity_decode($ba['link'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8')
                        );
                        $target   = ($ba['target'] ?? '_self')     === '_blank' ? '_blank' : '_self';
                        $autoplay = ($ba['autoplay'] ?? 'false')   === 'true';
                        $fit      = in_array($ba['fit'] ?? 'cover', ['cover', 'contain'], true) ? ($ba['fit'] ?? 'cover') : 'cover';
                        $cardBg   = $this->normalizeHexColor($ba['bg'] ?? '');
                        if ($cardBg === '') {
                            $cardBg = $bg;
                        }

                        $title = $this->sanitizeCardText(
                            html_entity_decode($m[2] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8')
                        );
                        $desc = $this->sanitizeCardText(
                            html_entity_decode($m[3] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8')
                        );

                        // A whole-card link must not nest inner anchors.
                        $anchorPattern = '/<a\b[^>]*>(.*?)<\/a>/is';
                        if ($link !== '') {
                            $title = preg_replace($anchorPattern, '$1', $title) ?? $title;
                            $desc  = preg_replace($anchorPattern, '$1', $desc)  ?? $desc;
                        }

                        $textAlign = 'center';
                        if ($align === 'left') {
                            $textAlign = 'left';
                        } elseif ($align === 'right') {
                            $textAlign = 'right';
                        }

                        $cardStyle = 'box-sizing:border-box;';
                        $cardStyle .= 'border:1px solid rgba(128,128,128,.25);';
                        if ($cardBg !== '') {
                            $cardStyle .= 'background-color:' . $cardBg . ';';
                        }
                        $cardStyle .= 'border-radius:' . $radius . ';';
                        $cardStyle .= 'padding:12px;text-align:' . $textAlign . ';';
                        $cardStyle .= 'display:flex;flex-direction:column;height:100%;';

                        // Fixed-ratio wrapper (not aspect-ratio on the <img> itself):
                        // theme stylesheets and flex sizing can override replaced
                        // elements, but a plain wrapper div always holds 16/9.
                        $isVideo = $image !== '' && (
                            preg_match('/\\.(mp4|webm|ogg|mov|m4v)(\\?|$)/i', $image) === 1
                            || str_contains($image, 'youtube.com')
                            || str_contains($image, 'youtu.be')
                            || str_contains($image, 'vimeo.com')
                        );
                        // Videos keep their intrinsic ratio at natural height so
                        // object-fit never letterboxes them inside a cropped box.
                        $imgBoxStyle = $isVideo
                            ? 'width:100%;overflow:hidden;'
                            : 'width:100%;aspect-ratio:16/9;overflow:hidden;';
                        $imgBoxStyle .= 'border-radius:' . $radius . ';margin-bottom:12px;';

                        if ($image !== '') {
                            $altText  = htmlspecialchars(strip_tags($title), ENT_QUOTES, 'UTF-8');
                            if ($isVideo && !str_contains($image, 'youtube.com') && !str_contains($image, 'youtu.be') && !str_contains($image, 'vimeo.com')) {
                                // Direct (self-hosted) video file.
                                $videoAttrs = ' style="width:100%;height:auto;object-fit:' . $fit . ';display:block;margin:0;"';
                                $videoAttrs .= ' playsinline';
                                if ($autoplay) {
                                    $videoAttrs .= ' autoplay muted';
                                } else {
                                    $videoAttrs .= ' controls';
                                }
                                $imgInner = '<video src="'
                                    . htmlspecialchars($image, ENT_QUOTES, 'UTF-8') . '"'
                                    . $videoAttrs . '></video>';
                            } elseif ($isVideo) {
                                // External embed (YouTube/Vimeo) - render as iframe.
                                $embedUrl = $this->toEmbedUrl($image, $autoplay);
                                $imgInner = '<div class="vl-video-embed" style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;max-width:100%;">'
                                    . '<iframe src="' . htmlspecialchars($embedUrl, ENT_QUOTES, 'UTF-8') . '" '
                                    . 'style="position:absolute;top:0;left:0;width:100%;height:100%;border:none" '
                                    . 'allow="autoplay; fullscreen" allowfullscreen loading="lazy"></iframe></div>';
                            } else {
                                $imgInner = '<img src="'
                                    . htmlspecialchars($image, ENT_QUOTES, 'UTF-8') . '"';
                                $imgInner .= ' alt="' . $altText . '" loading="lazy"';
                                $imgInner .= ' style="width:100%;height:100%;object-fit:' . $fit . ';';
                                $imgInner .= 'display:block;';
                                if ($lightbox) {
                                    $imgInner .= 'cursor:pointer;';
                                    $imgInner .= '" title="'
                                        . htmlspecialchars(__('Click to enlarge'), ENT_QUOTES, 'UTF-8');
                                    $imgInner .= '" data-lightbox="true" data-lightbox-src="'
                                        . htmlspecialchars($image, ENT_QUOTES, 'UTF-8');
                                    $hasLightboxImage = true;
                                }
                                $imgInner .= '">';
                            }
                            $imgHtml = '<div class="vl-card-image" style="'
                                . $imgBoxStyle . '">' . $imgInner . '</div>';
                        } else {
                            // Invisible spacer: reserves the image slot so titles
                            // stay aligned, without showing an empty box. Skipped
                            // when the whole group is imageless (see $groupHasImage).
                            $imgHtml = $groupHasImage
                                ? '<div class="vl-card-image-empty" style="'
                                    . $imgBoxStyle . 'box-sizing:border-box;"></div>'
                                : '';
                        }

                        $innerHtml = $imgHtml;
                        if ($title !== '') {
                            $innerHtml .= '<div class="vl-card-title"'
                                . ' style="font-weight:700;font-size:16px;margin:0 0 8px;">'
                                . $title . '</div>';
                        }
                        if ($desc !== '') {
                            $innerHtml .= '<div class="vl-card-desc"'
                                . ' style="font-size:14px;opacity:.8;margin-top:auto;">'
                                . $desc . '</div>';
                        }

                        if (trim(strip_tags($innerHtml)) === '' && $image === '') {
                            return '';
                        }

                        // Centered fixed cells (not growing): every row — full or
                        // orphan — is centered and all cards share one width.
                        $cellStyle = 'flex:0 1 260px;max-width:100%;min-width:0;';
                        $cellStyle .= 'box-sizing:border-box;';
                        if ($link !== '') {
                            $targetAttr = '';
                            if ($target === '_blank') {
                                $targetAttr = ' target="_blank" rel="noopener"';
                            }
                            $linkStyle = $cellStyle;
                            $linkStyle .= 'text-decoration:none;color:inherit;display:block;';

                            return '<a class="vl-card" href="'
                                . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '"'
                                . $targetAttr . ' style="' . $linkStyle . '">'
                                . '<div class="vl-card-body" style="' . $cardStyle . '">'
                                . $innerHtml . '</div>'
                                . '</a>';
                        }

                        return '<div class="vl-card" style="' . $cellStyle . '">'
                            . '<div class="vl-card-body" style="' . $cardStyle . '">'
                            . $innerHtml . '</div>'
                            . '</div>';
                    },
                    $inner
                );

                if (trim((string) $cardsHtml) === '') {
                    return '';
                }

                $this->hadShortcodes = true;

                $groupStyle = 'display:flex;flex-wrap:wrap;';
                // Centers every row, including a partially filled last one.
                $groupStyle .= 'justify-content:center;';
                $groupStyle .= 'gap:' . $gap . 'px;margin:24px 0;';
                if ($width !== '') {
                    $groupStyle .= 'max-width:' . $width . ';';
                    if ($halign === 'left') {
                        $groupStyle .= 'margin-left:0;margin-right:auto;';
                    } elseif ($halign === 'right') {
                        $groupStyle .= 'margin-left:auto;margin-right:0;';
                    } else {
                        $groupStyle .= 'margin-left:auto;margin-right:auto;';
                    }
                }

                $groupHtml = '<div class="vl-card-group" style="' . $groupStyle . '">'
                    . $cardsHtml
                    . '</div>';
                if ($lightbox && $hasLightboxImage) {
                    $groupHtml .= $this->renderLightboxOverlay();
                }

                return $groupHtml;
            },
            $content
        );
    }

    /**
     * [hero] — Hero section (split / CTA).
     *
     * Shortcode format:
     *   [hero variant="split"|"cta" bg="white|gray|dark|"] [shadow="none|sm|md|lg|xl"]
     *   radius="0px|4px|8px|12px|16px|24px|32px" image="..." imageAlt="..." mediaSide="right|left"
     *   badge="..." button1Text="..." button1Url="..." button1Target="_self" button1Style="primary|secondary"
     *   button2Text="..." button2Url="..." button2Target="_self" button2Style="primary|secondary"
     *   headingAlign="left|center|right" subtitleAlign="left|center|right"
     *   [hero_heading]...</hero_heading>[hero_subtitle]...</hero_subtitle>
     * [/hero]
     *
     * The PHP render produces theme-variable colors (var(--theme-*)), responsive layouts,
     * and inline styles for maximum compatibility across the four supported themes.
     */
    private function processHero(string $content): string
    {
        $pattern = '/<p>\s*\[hero(?:\s+([^\]]*))?\]'
            . '([\s\S]*?)\[\/hero\]\s*<\/p>'
            . '|\[hero(?:\s+([^\]]*))?\]'
            . '([\s\S]*?)\[\/hero\]/s';

        return preg_replace_callback(
            $pattern,
            function ($matches) {
                $gattrs = !empty($matches[1]) ? $matches[1] : ($matches[3] ?? '');
                $inner  = !empty($matches[2]) ? $matches[2] : ($matches[4] ?? '');

                $attrs   = $this->parseAttributes($gattrs);
                $variant = in_array($attrs['variant'] ?? '', ['split', 'cta'], true) ? ($attrs['variant'] ?? '') : 'split';

                $heading = $this->sanitizeCardText(
                    html_entity_decode($this->extractShortcodeContent($inner, 'hero_heading'), ENT_QUOTES | ENT_HTML5, 'UTF-8')
                );
                $subtitle = $this->sanitizeCardText(
                    html_entity_decode($this->extractShortcodeContent($inner, 'hero_subtitle'), ENT_QUOTES | ENT_HTML5, 'UTF-8')
                );

                $headingAlign  = in_array($attrs['headingAlign'] ?? '', ['left', 'center', 'right'], true) ? ($attrs['headingAlign'] ?? '') : '';
                $subtitleAlign = in_array($attrs['subtitleAlign'] ?? '', ['left', 'center', 'right'], true) ? ($attrs['subtitleAlign'] ?? '') : '';

                $rawBg = $attrs['bg'] ?? '';
                $bg    = in_array($rawBg, ['', 'white', 'gray', 'dark'], true)
                    || preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $rawBg) === 1
                    ? $rawBg
                    : '';
                $shadowColor = preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $attrs['shadowColor'] ?? '') === 1
                    ? $attrs['shadowColor']
                    : '';
                $lightbox = ($attrs['lightbox'] ?? 'false') === 'true';
                $shadow   = in_array($attrs['shadow'] ?? '', ['', 'none', 'sm', 'md', 'lg', 'xl'], true) ? ($attrs['shadow'] ?? '') : '';
                $radius   = preg_match('/^\d+(px)?$/', $attrs['radius'] ?? '') === 1 ? ($attrs['radius'] ?? '') : '';
                if ($radius !== '' && !str_ends_with($radius, 'px')) {
                    $radius .= 'px';
                }
                $width = $this->normalizeWidth($attrs['width'] ?? '');

                $mediaSide = in_array($attrs['mediaSide'] ?? '', ['right', 'left'], true) ? ($attrs['mediaSide'] ?? 'right') : 'right';
                $image     = $this->normalizeBlockUrl(
                    html_entity_decode($attrs['image'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8')
                );
                $mediaType      = ($attrs['mediaType'] ?? 'image') === 'carousel' ? 'carousel' : 'image';
                $carouselImages = array_values(array_filter(array_map(
                    fn (string $url): string => $this->normalizeBlockUrl(html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                    explode(',', (string) ($attrs['carouselImages'] ?? '')),
                )));
                $carouselAutoplay   = ($attrs['carouselAutoplay'] ?? 'true') === 'true';
                $carouselDuration   = min(30000, max(500, (int) ($attrs['carouselDuration'] ?? 5000)));
                $carouselShowArrows = ($attrs['carouselShowArrows'] ?? 'true')   === 'true';
                $carouselShowDots   = ($attrs['carouselShowDots'] ?? 'true')     === 'true';
                $carouselPauseBtn   = ($attrs['carouselPauseBtn'] ?? 'false')    === 'true';
                $carouselFit        = in_array($attrs['carouselFit'] ?? 'cover', ['cover', 'contain', 'fill', 'none', 'scale-down'], true)
                    ? ($attrs['carouselFit'] ?? 'cover')
                    : 'cover';
                $carouselHeightRaw = $attrs['carouselHeight'] ?? '100%';
                $carouselHeight    = preg_match('/^(?:\d+(?:\.\d+)?(?:px|%|vh|vw)|auto)$/', $carouselHeightRaw) === 1
                    ? $carouselHeightRaw
                    : '100%';
                $carouselTransition = in_array($attrs['carouselTransition'] ?? 'fade', ['fade', 'slide-left', 'slide-right', 'slide-up', 'zoom', 'none'], true)
                    ? ($attrs['carouselTransition'] ?? 'fade')
                    : 'fade';
                $carouselBg = preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $attrs['carouselBg'] ?? '') === 1
                    ? $attrs['carouselBg']
                    : '';
                $imageAlt = htmlspecialchars(strip_tags($heading), ENT_QUOTES, 'UTF-8');
                $rawAlt   = html_entity_decode($attrs['imageAlt'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (trim($rawAlt) !== '') {
                    $imageAlt = htmlspecialchars(strip_tags($rawAlt), ENT_QUOTES, 'UTF-8');
                }
                $imageFit = in_array($attrs['imageFit'] ?? '', ['cover', 'contain', 'fill', 'none', 'scale-down'], true)
                    ? ($attrs['imageFit'] ?? 'cover')
                    : 'cover';
                $mediaAutoplay = ($attrs['mediaAutoplay'] ?? 'false') === 'true';
                $badge         = $this->sanitizeCardText(
                    html_entity_decode($attrs['badge'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8')
                );
                $badgeBg = preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $attrs['badgeBg'] ?? '') === 1
                    ? $attrs['badgeBg']
                    : 'var(--theme-primary)';
                $badgeTextColor = preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $attrs['badgeTextColor'] ?? '') === 1
                    ? $attrs['badgeTextColor']
                    : '#fff';

                $button1 = $this->renderHeroButton(
                    $attrs['button1Text']      ?? '',
                    $attrs['button1Url']       ?? '',
                    $attrs['button1Target']    ?? '_self',
                    $attrs['button1Style']     ?? 'primary',
                    $attrs['button1Color']     ?? '',
                    $attrs['button1TextColor'] ?? '',
                );
                $button2 = $this->renderHeroButton(
                    $attrs['button2Text']      ?? '',
                    $attrs['button2Url']       ?? '',
                    $attrs['button2Target']    ?? '_self',
                    $attrs['button2Style']     ?? 'secondary',
                    $attrs['button2Color']     ?? '',
                    $attrs['button2TextColor'] ?? '',
                );

                if ($heading === '' && $subtitle === '' && $button1 === '' && $button2 === '' && $image === '' && $carouselImages === [] && $badge === '') {
                    return '';
                }

                $this->hadShortcodes = true;

                $sectionBg      = $this->heroSectionBackground($bg);
                $darkBackground = $variant === 'split' && ($bg === 'dark' || $this->isDarkHeroBackground($bg));
                $textColor      = $darkBackground ? '#f9fafb' : 'var(--theme-text)';
                $mutedColor     = $darkBackground ? 'rgba(255,255,255,0.7)' : 'var(--theme-text-muted)';

                $headingStyle = 'font-size:clamp(1.9rem,4vw,3rem);font-weight:700;line-height:1.15;margin:0 0 16px;color:' . $textColor . ';';
                if ($headingAlign !== '') {
                    $headingStyle .= 'text-align:' . $headingAlign . ';';
                }
                $subtitleStyle = 'font-size:1.125rem;margin:0 0 24px;color:' . $mutedColor . ';line-height:1.55;';
                if ($subtitleAlign !== '') {
                    $subtitleStyle .= 'text-align:' . $subtitleAlign . ';';
                }

                if ($variant === 'cta') {
                    $cardBg      = 'var(--theme-surface)';
                    $cardShadow  = $this->heroShadow($shadow, 'cta', $shadowColor);
                    $cardRadius  = $radius             !== '' ? $radius : '12px';
                    $buttonsHtml = $button1 . $button2 !== '' ? ($button1 . $button2) : '';
                    $buttonsWrap = $buttonsHtml        !== '' ? '<div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-top:32px;">' . $buttonsHtml . '</div>' : '';

                    $cardStyle = 'background:' . $cardBg . ';border-radius:' . $cardRadius . ';box-shadow:' . $cardShadow . ';padding:48px 32px;text-align:center;max-width:56rem;margin:0 auto;';

                    $sectionStyle = 'background:' . $sectionBg . ';padding:64px 20px;';
                    if ($width !== '') {
                        $sectionStyle .= 'max-width:' . $width . ';margin:0 auto;';
                    }

                    return '<section class="vl-hero vl-hero-cta" style="' . $sectionStyle . '">'
                        . '<div class="vl-hero-card" style="' . $cardStyle . '">'
                        . '<h2 class="vl-hero-heading" style="' . $headingStyle . '">' . $heading . '</h2>'
                        . '<p class="vl-hero-subtitle" style="' . $subtitleStyle . '">' . $subtitle . '</p>'
                        . $buttonsWrap
                        . '</div>'
                        . '</section>';
                }

                // split variant
                $mediaShadow = $this->heroShadow($shadow, 'split', $shadowColor);
                $mediaRadius = $radius !== '' ? $radius : '12px';
                $dir         = $mediaSide === 'left' ? 'row-reverse' : 'row';

                $textColStyle        = 'flex:1 1 300px;min-width:260px;';
                $mediaColStyle       = 'flex:1 1 300px;min-width:260px;position:relative;';
                $mediaDimensionStyle = $mediaType === 'carousel' && $carouselHeight !== '100%'
                    ? 'height:' . $carouselHeight . ';'
                    : 'aspect-ratio:16/9;';

                // Non-cover fits letterbox inside the 16:9 box — keep the box
                // transparent so the section background shows through.
                $mediaBoxBg = ($mediaType !== 'carousel' && $image !== '' && $imageFit !== 'cover') ? 'transparent' : 'var(--theme-surface)';

                $mediaInner = '';
                if ($mediaType === 'carousel' && $carouselImages !== []) {
                    $carouselUrls      = implode(',', $carouselImages);
                    $carouselShortcode = '[carousel images="' . $carouselUrls . '"'
                        . ' autoplay="' . ($carouselAutoplay ? 'true' : 'false') . '"'
                        . ' duration="' . $carouselDuration . '"'
                        . ' arrows="' . ($carouselShowArrows ? 'true' : 'false') . '"'
                        . ' dots="' . ($carouselShowDots ? 'true' : 'false') . '"'
                        . ' pauseBtn="' . ($carouselPauseBtn ? 'true' : 'false') . '"'
                        . ' fit="' . $carouselFit . '"'
                        . ' height="' . htmlspecialchars($carouselHeight, ENT_QUOTES, 'UTF-8') . '"'
                        . ' transition="' . $carouselTransition . '"'
                        . ($carouselBg !== '' ? ' bg="' . $carouselBg . '"' : '')
                        . ' lightbox="' . ($lightbox ? 'true' : 'false') . '"]';
                    $mediaInner = str_replace('mt-0 mb-8', 'm-0', $this->processCarousel($carouselShortcode));
                    $mediaInner = preg_replace('/(<div id="carousel-[^"]+" class="vl-carousel [^"]*" style=")/', '$1width:100%;flex:1 1 100%;', $mediaInner, 1) ?? $mediaInner;
                } elseif ($image !== '') {
                    $isVideo = preg_match('/\\.(mp4|webm|ogg|mov|m4v)(\\?|$)/i', $image) === 1
                        || str_contains($image, 'youtube.com')
                        || str_contains($image, 'youtu.be')
                        || str_contains($image, 'vimeo.com');
                    $lightboxAttrs = '';
                    if ($lightbox && !$isVideo) {
                        $lightboxSrc   = htmlspecialchars($image, ENT_QUOTES, 'UTF-8');
                        $lightboxAttrs = ' title="' . htmlspecialchars(__('Click to enlarge'), ENT_QUOTES, 'UTF-8')
                            . '" data-lightbox="true" data-lightbox-src="' . $lightboxSrc . '"';
                    }
                    if ($isVideo && !str_contains($image, 'youtube.com') && !str_contains($image, 'youtu.be') && !str_contains($image, 'vimeo.com')) {
                        $videoAttrs = ' style="width:100%;height:100%;object-fit:' . $imageFit . ';display:block;" playsinline';
                        if ($mediaAutoplay) {
                            $videoAttrs .= ' autoplay muted';
                        } else {
                            $videoAttrs .= ' controls';
                        }
                        $mediaInner = '<div class="vl-hero-media-inner" style="width:100%;aspect-ratio:16/9;overflow:hidden;border-radius:' . $mediaRadius . ';background:' . $mediaBoxBg . ';display:flex;align-items:center;justify-content:center;">'
                            . '<video src="' . htmlspecialchars($image, ENT_QUOTES, 'UTF-8') . '"' . $videoAttrs . '></video>'
                            . '</div>';
                    } elseif ($isVideo) {
                        $embedUrl   = $this->toEmbedUrl($image, $mediaAutoplay);
                        $mediaInner = '<div class="vl-hero-media-inner" style="width:100%;aspect-ratio:16/9;overflow:hidden;border-radius:' . $mediaRadius . ';background:' . $mediaBoxBg . ';display:flex;align-items:center;justify-content:center;">'
                            . '<div class="vl-video-embed" style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;max-width:100%;width:100%;">'
                            . '<iframe src="' . htmlspecialchars($embedUrl, ENT_QUOTES, 'UTF-8') . '" '
                            . 'style="position:absolute;top:0;left:0;width:100%;height:100%;border:none" '
                            . 'allow="autoplay; fullscreen" allowfullscreen loading="lazy"></iframe></div>'
                            . '</div>';
                    } else {
                        $mediaInner = '<div class="vl-hero-media-inner" style="width:100%;aspect-ratio:16/9;overflow:hidden;border-radius:' . $mediaRadius . ';background:' . $mediaBoxBg . ';display:flex;align-items:center;justify-content:center;">'
                            . '<img src="' . htmlspecialchars($image, ENT_QUOTES, 'UTF-8') . '" alt="' . $imageAlt . '" loading="lazy" style="width:100%;height:100%;object-fit:' . $imageFit . ';display:block;' . ($lightbox ? 'cursor:zoom-in;' : '') . '"' . $lightboxAttrs . '>'
                            . '</div>';
                    }
                } else {
                    $mediaInner = '<div class="vl-hero-media-inner" style="width:100%;aspect-ratio:16/9;overflow:hidden;border-radius:' . $mediaRadius . ';background:var(--theme-surface);display:flex;align-items:center;justify-content:center;color:var(--theme-text-muted);font-size:13px;">'
                        . __('Add a media — click here')
                        . '</div>';
                }
                if ($badge !== '') {
                    $mediaInner .= '<div class="vl-hero-badge" style="position:absolute;bottom:-25px;right:-10px;background:' . $badgeBg . ';color:' . $badgeTextColor . ';padding:6px 14px;border-radius:10px;font-size:12px;font-weight:600;box-shadow:0 4px 12px rgba(0,0,0,0.25);">' . $badge . '</div>';
                }
                $mediaHtml = '<div class="vl-hero-media" style="' . $mediaColStyle . $mediaDimensionStyle . 'overflow:visible;border-radius:' . $mediaRadius . ';box-shadow:' . $mediaShadow . ';background:' . $mediaBoxBg . ';display:flex;align-items:center;justify-content:center;">'
                    . $mediaInner
                    . '</div>';

                $buttonsHtml = $button1 . ($button2 !== '' ? $button2 : '');
                $buttonsWrap = $buttonsHtml !== '' ? '<div class="vl-hero-buttons" style="display:flex;gap:12px;flex-wrap:wrap;margin-top:16px;">' . $buttonsHtml . '</div>' : '';

                $sectionStyle = 'background:' . $sectionBg . ';padding:64px 20px;';
                if ($width !== '') {
                    $sectionStyle .= 'max-width:' . $width . ';margin:0 auto;';
                }
                if ($shadow !== '') {
                    $sectionStyle .= 'box-shadow:' . $mediaShadow . ';';
                }

                return '<section class="vl-hero vl-hero-split" style="' . $sectionStyle . '">'
                    . '<div class="vl-hero-row" style="max-width:72rem;margin:0 auto;display:flex;align-items:center;gap:32px;flex-wrap:wrap;flex-direction:' . $dir . ';">'
                    . '<div class="vl-hero-text" style="' . $textColStyle . '">'
                    . '<h2 class="vl-hero-heading" style="' . $headingStyle . '">' . $heading . '</h2>'
                    . '<p class="vl-hero-subtitle" style="' . $subtitleStyle . '">' . $subtitle . '</p>'
                    . $buttonsWrap
                    . '</div>'
                    . $mediaHtml
                    . '</div>'
                    . '</section>';
            },
            $content
        );
    }

    /**
     * Extract the inner content of a [tag]…[/tag] shortcode from the hero block.
     */
    private function extractShortcodeContent(string $inner, string $tag): string
    {
        if (preg_match('/\[' . $tag . '\]([\s\S]*?)\[\/' . $tag . '\]/s', $inner, $m)) {
            return $m[1];
        }

        return '';
    }

    /**
     * Render one hero button (theme-styled). Returns '' when the label is empty.
     */
    private function renderHeroButton(string $text, string $url, string $target, string $style, string $color = '', string $textColor = ''): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $url       = $this->normalizeBlockUrl(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $style     = $style === 'secondary' ? 'secondary' : 'primary';
        $btnRadius = '8px';
        $btnStyle  = 'display:inline-block;padding:14px 28px;border-radius:' . $btnRadius . ';font-size:1rem;font-weight:600;text-decoration:none;transition:background-color .2s;';

        $validTextColor = preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $textColor) === 1 ? $textColor : '';
        if (preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $color) === 1) {
            $defaultTextColor = $this->isDarkHeroBackground($color) ? '#fff' : '#111827';
            $btnStyle .= 'background:' . $color . ';color:' . ($validTextColor ?: $defaultTextColor) . ';border:1px solid ' . $color . ';';
        } elseif ($style === 'primary') {
            $btnStyle .= 'background:var(--theme-primary);color:' . ($validTextColor ?: '#fff') . ';';
        } else {
            $btnStyle .= 'background:var(--theme-surface);color:' . ($validTextColor ?: 'var(--theme-text)') . ';border:1px solid var(--theme-secondary);';
        }

        $label = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        if ($url === '') {
            return '<span class="vl-hero-btn vl-hero-btn-' . $style . '" style="' . $btnStyle . '">' . $label . '</span>';
        }

        $targetAttr = $target === '_blank' ? ' target="_blank" rel="noopener"' : '';

        return '<a class="vl-hero-btn vl-hero-btn-' . $style . '" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"' . $targetAttr . ' style="' . $btnStyle . '">' . $label . '</a>';
    }

    /**
     * Resolve the section background for a hero element.
     */
    private function heroSectionBackground(string $bg): string
    {
        if (preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $bg) === 1) {
            return $bg;
        }

        switch ($bg) {
            case 'white':
                return '#ffffff';
            case 'gray':
                return '#f3f4f6';
            case 'dark':
                return '#111827';
            default:
                // Soft theme tint that follows the active theme's primary color.
                return 'linear-gradient(135deg,color-mix(in srgb,var(--theme-primary) 8%,var(--theme-surface)),var(--theme-surface) 60%,color-mix(in srgb,var(--theme-primary) 10%,var(--theme-surface)))';
        }
    }

    private function isDarkHeroBackground(string $color): bool
    {
        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $color, $matches) !== 1) {
            return false;
        }

        $hex = strlen($matches[1]) === 3
            ? implode('', array_map(static fn (string $digit): string => $digit . $digit, str_split($matches[1])))
            : $matches[1];
        $channels = array_map(static fn (string $channel): float => hexdec($channel) / 255, str_split($hex, 2));
        $linear   = array_map(
            static fn (float $channel): float => $channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4,
            $channels,
        );

        return (0.2126 * $linear[0]) + (0.7152 * $linear[1]) + (0.0722 * $linear[2]) < 0.179;
    }

    /**
     * Resolve the shadow for a hero element.
     */
    private function heroShadow(string $shadow, string $context, string $color = ''): string
    {
        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $color, $matches) === 1) {
            $hex = strlen($matches[1]) === 3
                ? implode('', array_map(static fn (string $digit): string => $digit . $digit, str_split($matches[1])))
                : $matches[1];
            $channels       = array_map(static fn (string $channel): int => hexdec($channel), str_split($hex, 2));
            $rgba           = static fn (float $alpha): string => 'rgba(' . implode(',', $channels) . ',' . $alpha . ')';
            $coloredShadows = [
                'none' => 'none',
                'sm'   => '0 1px 2px ' . $rgba(0.12),
                'md'   => '0 4px 6px -1px ' . $rgba(0.2) . ',0 2px 4px -2px ' . $rgba(0.2),
                'lg'   => '0 10px 15px -3px ' . $rgba(0.2) . ',0 4px 6px -4px ' . $rgba(0.2),
                'xl'   => '0 20px 25px -5px ' . $rgba(0.2) . ',0 8px 10px -6px ' . $rgba(0.2),
            ];
            if (isset($coloredShadows[$shadow])) {
                return $coloredShadows[$shadow];
            }

            return $context === 'cta'
                ? '0 25px 50px -12px ' . $rgba(0.25)
                : '0 20px 40px -12px ' . $rgba(0.25);
        }

        $map = [
            'none' => 'none',
            'sm'   => '0 1px 2px rgba(0,0,0,.05)',
            'md'   => '0 4px 6px -1px rgba(0,0,0,.1),0 2px 4px -2px rgba(0,0,0,.1)',
            'lg'   => '0 10px 15px -3px rgba(0,0,0,.1),0 4px 6px -4px rgba(0,0,0,.1)',
            'xl'   => '0 20px 25px -5px rgba(0,0,0,.1),0 8px 10px -6px rgba(0,0,0,.1)',
        ];

        if (isset($map[$shadow])) {
            return $map[$shadow];
        }

        // Default: soft big shadow (matches the reference shadow-2xl look).
        return $context === 'cta'
            ? '0 25px 50px -12px rgba(0,0,0,.25)'
            : '0 20px 40px -12px rgba(0,0,0,.25)';
    }
}
