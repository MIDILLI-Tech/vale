<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure\Content;

use PHPUnit\Framework\TestCase;
use ValeApp\Application\Form\FormAccessService;
use ValeApp\Application\Form\FormCaptchaService;
use ValeApp\Domain\Content\Entity\Post;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Form\Entity\Form;
use ValeApp\Domain\Form\Repository\FormRepositoryInterface;
use ValeApp\Domain\Media\Entity\Media;
use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
use ValeApp\Domain\Newsletter\Entity\Subscriber;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Entity\Product;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Infrastructure\Content\ShortcodeProcessor;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Security\CsrfManager;
use ValeApp\Infrastructure\Session\SessionManager;

class ShortcodeProcessorTest extends TestCase
{
    private ShortcodeProcessor $processor;

    protected function setUp(): void
    {
        $this->processor = $this->createProcessor();
    }

    // ── Helper ──────────────────────────────────────────────

    private function createProcessor(
        ?FormRepositoryInterface $forms = null,
        ?CsrfManager $csrf = null,
        ?SessionManager $session = null,
        ?PostRepositoryInterface $posts = null,
        ?MediaRepositoryInterface $media = null,
        ?ProductRepositoryInterface $products = null,
        ?DbalSettingsRepository $settings = null,
        ?FormAccessService $formAccess = null,
        ?FormCaptchaService $formCaptcha = null,
        string $basePath = '',
    ): ShortcodeProcessor {
        $settings = $settings ?? $this->createStub(DbalSettingsRepository::class);

        return new ShortcodeProcessor(
            $forms      ?? $this->createStub(FormRepositoryInterface::class),
            $csrf       ?? $this->createStub(CsrfManager::class),
            $session    ?? $this->createStub(SessionManager::class),
            $posts      ?? $this->createStub(PostRepositoryInterface::class),
            $media      ?? $this->createStub(MediaRepositoryInterface::class),
            $products   ?? $this->createStub(ProductRepositoryInterface::class),
            $settings,
            $formAccess  ?? $this->createFormAccess(),
            $formCaptcha ?? new FormCaptchaService(
                $this->createStub(FormRepositoryInterface::class),
                $settings,
            ),
            $basePath,
        );
    }

    private function createFormAccess(?int $userId = null, array $userTags = []): FormAccessService
    {
        $session = $this->createStub(SessionManager::class);
        $session->method('get')->willReturn($userId);

        $subscribers = $this->createStub(SubscriberRepositoryInterface::class);
        $subscriber  = null;
        if ($userId !== null) {
            $subscriber = new Subscriber('member@example.com', 'token');
            $subscriber->setTags($userTags);
        }
        $subscribers->method('findByUserId')->willReturn($subscriber);

        return new FormAccessService($session, $subscribers, $this->createSettings(['membership_enabled' => '1']));
    }

    private function createSettings(array $map = []): DbalSettingsRepository
    {
        $settings = $this->createStub(DbalSettingsRepository::class);
        $defaults = [
            'advertisement_enabled'           => '0',
            'advertisement_code'              => '',
            'newsletter_enabled'              => '1',
            'form_builder_enabled'            => '1',
            'multi_language_content'          => '0',
            'default_locale'                  => 'en',
            'i18n_display_originals_fallback' => '1',
            'routing_page_prefix'             => '',
            'routing_shop_prefix'             => '/shop',
            'active_theme'                    => 'default',
        ];
        $all = array_merge($defaults, $map);
        $settings->method('get')->willReturnCallback(
            fn (string $key, mixed $default = null) => $all[$key] ?? $default,
        );
        return $settings;
    }

    private function makeForm(string $slug = 'contact', bool $enabled = true, string $language = 'en', ?int $translationOf = null): Form
    {
        $form = new Form('Contact', new Slug($slug));
        $form->setEnabled($enabled);
        $form->setLanguage($language);
        $form->setTranslationOf($translationOf);
        $form->setFields([
            ['type' => 'text', 'label' => 'Name', 'name' => 'name', 'required' => true, 'width' => '50'],
            ['type' => 'email', 'label' => 'Email', 'name' => 'email', 'required' => true, 'width' => '50'],
            ['type' => 'textarea', 'label' => 'Message', 'name' => 'message', 'required' => false, 'width' => '100'],
            ['type' => 'select', 'label' => 'Topic', 'name' => 'topic', 'options' => ['General', 'Support'], 'width' => '100'],
            ['type' => 'radio', 'label' => 'Priority', 'name' => 'priority', 'options' => ['Low', 'High'], 'width' => '50'],
            ['type' => 'checkbox', 'label' => 'Options', 'name' => 'options', 'options' => ['Opt A', 'Opt B'], 'width' => '50'],
            ['type' => 'newsletter_subscribe', 'label' => 'Subscribe', 'name' => 'sub', 'width' => '100'],
        ]);
        $form->setSettings(['captcha' => false, 'allow_multiple' => '1']);
        $form->setSubmitLabel('Send');
        return $form;
    }

    private function makeProduct(int $id = 1, string $name = 'Widget', float $price = 19.99): Product
    {
        $product = new Product($name, new Slug('widget'));
        $product->setPrice($price);
        $product->setShortDescription('A fine widget');
        $product->setStatus('published');
        return $product;
    }

    // ── Basic ───────────────────────────────────────────────

    public function testHadShortcodesIsFalseByDefault(): void
    {
        self::assertFalse($this->processor->hadShortcodes());
    }

    public function testSubtitleBadgeWrapsOnlyTheTrackedVideo(): void
    {
        // A trackless video followed by a subtitled one: only the second may be
        // wrapped. Regression — the old lazy glob leaked across the first
        // </video> and wrapped both in a single container with one badge.
        $content = '<video controls><source src="a.mp4" type="video/mp4"></video>'
            . '<video controls><source src="b.mp4" type="video/mp4">'
            . '<track kind="subtitles" src="b.vtt" srclang="en"></video>';

        $out = $this->processor->process($content);

        self::assertSame(2, substr_count($out, '<video'));
        self::assertSame(1, substr_count($out, 'vl-video-sub-wrap'));
        self::assertSame(1, substr_count($out, 'vl-video-sub-badge'));
    }

    public function testSubtitleBadgeIsIdempotentAndSkipsTracklessVideos(): void
    {
        $trackless = '<video controls><source src="a.mp4" type="video/mp4"></video>';
        self::assertStringNotContainsString('vl-video-sub-badge', $this->processor->process($trackless));

        $subtitled = '<video controls><track kind="subtitles" src="a.vtt" srclang="en"></video>';
        $once      = $this->processor->process($subtitled);
        self::assertSame(1, substr_count($once, 'vl-video-sub-badge'));

        // A second pass over already-wrapped markup must not double-wrap.
        $twice = $this->processor->process($once);
        self::assertSame(1, substr_count($twice, 'vl-video-sub-wrap'));
        self::assertSame(1, substr_count($twice, 'vl-video-sub-badge'));
    }

    public function testProcessWithoutShortcodesPassesThrough(): void
    {
        $content = '<p>Hello world</p>';
        self::assertSame($content, $this->processor->process($content));
    }

    public function testProcessResetsStateBetweenCalls(): void
    {
        $this->processor->process('[carousel images="https://a.jpg"]');
        self::assertTrue($this->processor->hadShortcodes());

        $this->processor->process('No shortcodes here');
        self::assertFalse($this->processor->hadShortcodes());
    }

    // ── Spacer ──────────────────────────────────────────────

    public function testSpacerShortcode(): void
    {
        $content   = '<p>Before</p><!--spacer size=40 unit=px--><p>After</p>';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('height:40px', $processed);
        self::assertStringContainsString('display:block', $processed);
        self::assertStringContainsString('aria-hidden="true"', $processed);
        self::assertStringNotContainsString('<!--spacer', $processed);
    }

    // ── Form ────────────────────────────────────────────────

    public function testFormShortcodeRendersForm(): void
    {
        $forms   = $this->createStub(FormRepositoryInterface::class);
        $forms->method('findBySlug')->willReturn($this->makeForm());

        $csrf = $this->createStub(CsrfManager::class);
        $csrf->method('getToken')->willReturn('tok123');

        $processor = $this->createProcessor(forms: $forms, csrf: $csrf);

        $content   = '<p>[form slug="contact"]</p>';
        $processed = $processor->process($content);

        self::assertStringContainsString('vl-embedded-form', $processed);
        self::assertStringContainsString('name="_csrf_token" value="tok123"', $processed);
        self::assertStringContainsString('action="/form/contact"', $processed);
        self::assertStringContainsString('Send</button>', $processed);
        self::assertStringContainsString('vl-field-col', $processed);
        self::assertTrue($processor->hadShortcodes());
    }

    public function testFormShortcodeStripsWrappingPTag(): void
    {
        $forms = $this->createStub(FormRepositoryInterface::class);
        $forms->method('findBySlug')->willReturn($this->makeForm());

        $processor = $this->createProcessor(forms: $forms);

        $content   = '<p>[form slug="contact"]</p>';
        $processed = $processor->process($content);

        self::assertStringNotContainsString('<p>', $processed);
        self::assertStringContainsString('vl-embedded-form', $processed);
    }

    public function testFormShortcodeBareWithoutPTag(): void
    {
        $forms = $this->createStub(FormRepositoryInterface::class);
        $forms->method('findBySlug')->willReturn($this->makeForm());

        $processor = $this->createProcessor(forms: $forms);

        $content   = '[form slug="contact"]';
        $processed = $processor->process($content);

        self::assertStringContainsString('vl-embedded-form', $processed);
    }

    public function testFormShortcodeWithQuotes(): void
    {
        $forms = $this->createStub(FormRepositoryInterface::class);
        $forms->method('findBySlug')->willReturn($this->makeForm());

        $processor = $this->createProcessor(forms: $forms);

        $content   = "<p>[form slug='contact']</p>";
        $processed = $processor->process($content);

        self::assertStringContainsString('vl-embedded-form', $processed);
    }

    public function testFormShortcodeDisabledFormBuilder(): void
    {
        $settings  = $this->createSettings(['form_builder_enabled' => '0']);
        $processor = $this->createProcessor(settings: $settings);

        $content   = '[form slug="contact"]';
        $processed = $processor->process($content);

        self::assertSame('', $processed);
    }

    public function testFormShortcodeNullForm(): void
    {
        $forms = $this->createStub(FormRepositoryInterface::class);
        $forms->method('findBySlug')->willReturn(null);

        $processor = $this->createProcessor(forms: $forms);

        $content   = '[form slug="nonexistent"]';
        $processed = $processor->process($content);

        self::assertSame('', $processed);
    }

    public function testFormShortcodeDisabledForm(): void
    {
        $forms = $this->createStub(FormRepositoryInterface::class);
        $form  = $this->makeForm();
        $form->setEnabled(false);
        $forms->method('findBySlug')->willReturn($form);

        $processor = $this->createProcessor(forms: $forms);

        $content   = '[form slug="contact"]';
        $processed = $processor->process($content);

        self::assertSame('', $processed);
    }

    public function testFormShortcodeWithCaptcha(): void
    {
        $forms = $this->createStub(FormRepositoryInterface::class);
        $form  = $this->makeForm();
        $form->setSettings(['captcha' => true, 'allow_multiple' => '1']);
        $forms->method('findBySlug')->willReturn($form);

        $csrf = $this->createStub(CsrfManager::class);
        $csrf->method('getToken')->willReturn('csrf');

        $session = $this->createMock(SessionManager::class);
        $session->expects($this->once())->method('set')->with('captcha_answer', $this->anything());

        $processor = $this->createProcessor(forms: $forms, csrf: $csrf, session: $session);

        $content   = '[form slug="contact"]';
        $processed = $processor->process($content);

        self::assertStringContainsString('captcha', $processed);
        self::assertStringContainsString('spam protection', $processed);
    }

    public function testFormShortcodeCaptchaTypeNoneRendersNoCaptcha(): void
    {
        $forms = $this->createStub(FormRepositoryInterface::class);
        $form  = $this->makeForm();
        $form->setSettings(['captcha' => false, 'captcha_type' => 'none', 'allow_multiple' => '1']);
        $forms->method('findBySlug')->willReturn($form);

        $processor = $this->createProcessor(forms: $forms);

        $processed = $processor->process('[form slug="contact"]');

        self::assertStringNotContainsString('name="captcha"', $processed);
        self::assertStringNotContainsString('spam protection', $processed);
        self::assertStringNotContainsString('h-captcha', $processed);
        self::assertStringNotContainsString('cf-turnstile', $processed);
        self::assertStringNotContainsString('_hp_name', $processed);
    }

    public function testFormShortcodeCaptchaTypeMathRendersQuestion(): void
    {
        $forms = $this->createStub(FormRepositoryInterface::class);
        $form  = $this->makeForm();
        $form->setSettings(['captcha' => true, 'captcha_type' => 'math', 'allow_multiple' => '1']);
        $forms->method('findBySlug')->willReturn($form);

        $session = $this->createMock(SessionManager::class);
        $session->expects($this->once())->method('set')->with('captcha_answer', $this->anything());

        $processor = $this->createProcessor(forms: $forms, session: $session);

        $processed = $processor->process('[form slug="contact"]');

        self::assertStringContainsString('name="captcha"', $processed);
        self::assertStringContainsString('id="vl-form-contact-captcha"', $processed);
        self::assertStringNotContainsString('g-recaptcha-response', $processed);
        self::assertStringNotContainsString('cf-turnstile', $processed);
    }

    public function testFormShortcodeCaptchaTypeTurnstileRendersWidget(): void
    {
        $forms = $this->createStub(FormRepositoryInterface::class);
        $form  = $this->makeForm();
        $form->setSettings([
            'captcha'        => true,
            'captcha_type'   => 'turnstile',
            'allow_multiple' => '1',
        ]);
        $forms->method('findBySlug')->willReturn($form);

        $settings = $this->createSettings([
            'turnstile_site_key'   => 'ts-site-key',
            'turnstile_secret_key' => 'ts-secret',
        ]);

        $processor = $this->createProcessor(forms: $forms, settings: $settings);

        $processed = $processor->process('[form slug="contact"]');

        self::assertStringContainsString('class="cf-turnstile', $processed);
        self::assertStringContainsString('data-sitekey="ts-site-key"', $processed);
        self::assertStringContainsString('data-theme="auto"', $processed);
        self::assertStringContainsString('data-size="flexible"', $processed);
        self::assertStringContainsString('challenges.cloudflare.com/turnstile/v0/api.js', $processed);
        self::assertStringNotContainsString('name="captcha"', $processed);
        self::assertStringNotContainsString('spam protection', $processed);
    }

    public function testFormShortcodeCaptchaTypeHcaptchaRendersWidget(): void
    {
        $forms = $this->createStub(FormRepositoryInterface::class);
        $form  = $this->makeForm();
        $form->setSettings([
            'captcha'        => true,
            'captcha_type'   => 'hcaptcha',
            'allow_multiple' => '1',
        ]);
        $forms->method('findBySlug')->willReturn($form);

        $settings = $this->createSettings([
            'hcaptcha_site_key'   => 'hc-site-key',
            'hcaptcha_secret_key' => 'hc-secret',
        ]);

        $processor = $this->createProcessor(forms: $forms, settings: $settings);

        $processed = $processor->process('[form slug="contact"]');

        self::assertStringContainsString('class="h-captcha', $processed);
        self::assertStringContainsString('data-sitekey="hc-site-key"', $processed);
        // hCaptcha only supports light|dark themes — no data-theme="auto".
        self::assertStringNotContainsString('data-theme="auto"', $processed);
        self::assertStringContainsString('js.hcaptcha.com/1/api.js', $processed);
        self::assertStringNotContainsString('name="captcha"', $processed);
    }

    public function testFormShortcodeCaptchaTypeRecaptchaRendersTokenField(): void
    {
        $forms = $this->createStub(FormRepositoryInterface::class);
        $form  = $this->makeForm();
        $form->setSettings([
            'captcha'        => true,
            'captcha_type'   => 'recaptcha',
            'allow_multiple' => '1',
        ]);
        $forms->method('findBySlug')->willReturn($form);

        $settings = $this->createSettings([
            'recaptcha_site_key'   => 'rc-site-key',
            'recaptcha_secret_key' => 'rc-secret',
        ]);

        $processor = $this->createProcessor(forms: $forms, settings: $settings);

        $processed = $processor->process('[form slug="contact"]');

        self::assertStringContainsString('name="g-recaptcha-response"', $processed);
        self::assertStringContainsString('id="vl-form-contact-g-recaptcha-response"', $processed);
        self::assertStringContainsString('recaptcha/api.js?render=rc-site-key', $processed);
        self::assertStringNotContainsString('name="captcha"', $processed);
    }

    public function testFormShortcodeCaptchaTypeHoneypotRendersHiddenField(): void
    {
        $forms = $this->createStub(FormRepositoryInterface::class);
        $form  = $this->makeForm();
        $form->setSettings([
            'captcha'              => true,
            'captcha_type'         => 'honeypot',
            'honeypot_enabled'     => true,
            'honeypot_field_name'  => '_hp_contact',
            'allow_multiple'       => '1',
        ]);
        $forms->method('findBySlug')->willReturn($form);

        $processor = $this->createProcessor(forms: $forms);

        $processed = $processor->process('[form slug="contact"]');

        self::assertStringContainsString('name="_hp_contact"', $processed);
        self::assertStringContainsString('display:none', $processed);
        self::assertStringNotContainsString('name="captcha"', $processed);
        self::assertStringNotContainsString('cf-turnstile', $processed);
    }

    public function testFormShortcodeCaptchaProviderWithoutKeysIsWithheld(): void
    {
        $forms = $this->createStub(FormRepositoryInterface::class);
        $form  = $this->makeForm();
        $form->setSettings([
            'captcha'        => true,
            'captcha_type'   => 'turnstile',
            'allow_multiple' => '1',
        ]);
        $forms->method('findBySlug')->willReturn($form);

        $processor = $this->createProcessor(forms: $forms);

        $processed = $processor->process('[form slug="contact"]');

        self::assertStringContainsString('This form is currently unavailable.', $processed);
        self::assertStringContainsString('contact the site administrator', $processed);
        self::assertStringNotContainsString('vl-embedded-form', $processed);
        self::assertStringNotContainsString('cf-turnstile', $processed);
    }

    public function testFormShortcodeMathCaptchaNeedsNoKeys(): void
    {
        $forms = $this->createStub(FormRepositoryInterface::class);
        $form  = $this->makeForm();
        $form->setSettings(['captcha' => true, 'captcha_type' => 'math', 'allow_multiple' => '1']);
        $forms->method('findBySlug')->willReturn($form);

        $processor = $this->createProcessor(forms: $forms);

        $processed = $processor->process('[form slug="contact"]');

        self::assertStringContainsString('vl-embedded-form', $processed);
        self::assertStringContainsString('name="captcha"', $processed);
        self::assertStringNotContainsString('currently unavailable', $processed);
    }

    public function testFormShortcodeAllowMultipleFalse(): void
    {
        $forms = $this->createStub(FormRepositoryInterface::class);
        $form  = $this->makeForm();
        $form->setSettings(['captcha' => false, 'allow_multiple' => '0']);
        $forms->method('findBySlug')->willReturn($form);

        $csrf = $this->createStub(CsrfManager::class);
        $csrf->method('getToken')->willReturn('csrf');

        $processor = $this->createProcessor(forms: $forms, csrf: $csrf);

        $content   = '[form slug="contact"]';
        $processed = $processor->process($content);

        self::assertStringContainsString('localStorage.getItem', $processed);
        self::assertStringContainsString('vl_form_submitted_', $processed);
        self::assertStringContainsString('already_submitted', $processed);
        self::assertStringContainsString('class=\"rounded-lg', $processed);
        self::assertStringNotContainsString('\\\\"', $processed);
    }

    public function testFormShortcodeWithMultiLanguageResolvesTranslation(): void
    {
        $trForm = $this->makeForm('contact-tr', language: 'tr', translationOf: 1);
        $trForm->setName('Iletisim');

        $forms        = $this->createStub(FormRepositoryInterface::class);
        $originalForm = $this->makeForm();
        $originalForm->setId(1);
        $forms->method('findBySlug')->willReturn($originalForm);
        $forms->method('findTranslations')->willReturn([$trForm]);

        $settings = $this->createSettings(['multi_language_content' => '1']);

        $processor = $this->createProcessor(forms: $forms, settings: $settings);

        $post = new Post('TR Post', new Slug('tr-post'), 1);
        $post->setLanguage('tr');

        $content   = '[form slug="contact"]';
        $processed = $processor->process($content, $post);

        self::assertStringContainsString('vl-embedded-form', $processed);
        self::assertStringContainsString('form/contact-tr', $processed);
    }

    public function testFormShortcodeMultiLanguageFallbackDisabled(): void
    {
        $forms = $this->createStub(FormRepositoryInterface::class);
        $form  = $this->makeForm();
        $form->setTranslationOf(1);
        $forms->method('findBySlug')->willReturn($form);
        $forms->method('findTranslations')->willReturn([]);

        $settings = $this->createSettings([
            'multi_language_content'          => '1',
            'i18n_display_originals_fallback' => '0',
        ]);

        $processor = $this->createProcessor(forms: $forms, settings: $settings);

        $post = new Post('TR Post', new Slug('tr-post'), 1);
        $post->setLanguage('tr');

        $content   = '[form slug="contact"]';
        $processed = $processor->process($content, $post);

        self::assertSame('', $processed);
    }

    public function testFormShortcodeMultiLanguageFallbackToOriginal(): void
    {
        $originalForm = $this->makeForm();
        $originalForm->setTranslationOf(null);

        $forms          = $this->createStub(FormRepositoryInterface::class);
        $translatedForm = $this->makeForm();
        $translatedForm->setTranslationOf(1);
        $forms->method('findBySlug')->willReturn($translatedForm);
        $forms->method('findTranslations')->willReturn([]);
        $forms->method('findById')->willReturn($originalForm);

        $settings = $this->createSettings([
            'multi_language_content'          => '1',
            'i18n_display_originals_fallback' => '1',
        ]);

        $processor = $this->createProcessor(forms: $forms, settings: $settings);

        $post = new Post('TR Post', new Slug('tr-post'), 1);
        $post->setLanguage('tr');

        $content   = '[form slug="contact"]';
        $processed = $processor->process($content, $post);

        self::assertStringContainsString('vl-embedded-form', $processed);
    }

    public function testFormShortcodeExceptionReturnsEmpty(): void
    {
        $forms = $this->createStub(FormRepositoryInterface::class);
        $forms->method('findBySlug')->willThrowException(new \RuntimeException('DB error'));

        $processor = $this->createProcessor(forms: $forms);

        $content   = '[form slug="contact"]';
        $processed = $processor->process($content);

        self::assertSame('', $processed);
    }

    public function testFormShortcodeHiddenWhenRegistrationRequired(): void
    {
        $forms = $this->createStub(FormRepositoryInterface::class);
        $form  = $this->makeForm();
        $form->setSettings(['captcha' => false, 'allow_multiple' => '1', 'only_registered' => true]);
        $forms->method('findBySlug')->willReturn($form);

        $formAccess = $this->createFormAccess();

        $processor = $this->createProcessor(forms: $forms, formAccess: $formAccess);

        $processed = $processor->process('[form slug="contact"]');

        self::assertStringContainsString('Sign in to access this form', $processed);
        self::assertStringNotContainsString('vl-embedded-form', $processed);
        self::assertStringNotContainsString('action="/form/contact"', $processed);
    }

    public function testFormShortcodeHiddenWhenMembershipTagMissing(): void
    {
        $forms = $this->createStub(FormRepositoryInterface::class);
        $form  = $this->makeForm();
        $form->setSettings(['captcha' => false, 'allow_multiple' => '1', 'access_tags' => ['VIP']]);
        $forms->method('findBySlug')->willReturn($form);

        $formAccess = $this->createFormAccess(userId: 5, userTags: []);

        $processor = $this->createProcessor(forms: $forms, formAccess: $formAccess);

        $processed = $processor->process('[form slug="contact"]');

        self::assertStringContainsString('This form is for members only.', $processed);
        self::assertStringNotContainsString('vl-embedded-form', $processed);
    }

    public function testFormShortcodeRenderedWhenAccessGranted(): void
    {
        $forms = $this->createStub(FormRepositoryInterface::class);
        $form  = $this->makeForm();
        $form->setSettings(['captcha' => false, 'allow_multiple' => '1', 'access_tags' => ['VIP']]);
        $forms->method('findBySlug')->willReturn($form);

        $formAccess = $this->createFormAccess(userId: 5, userTags: ['VIP']);

        $processor = $this->createProcessor(forms: $forms, formAccess: $formAccess);

        $processed = $processor->process('[form slug="contact"]');

        self::assertStringContainsString('vl-embedded-form', $processed);
        self::assertStringContainsString('action="/form/contact"', $processed);
    }

    // ── Form Field Types ────────────────────────────────────

    public function testFormRendersAllFieldTypes(): void
    {
        $forms = $this->createStub(FormRepositoryInterface::class);
        $form  = $this->makeForm();
        $forms->method('findBySlug')->willReturn($form);

        $processor = $this->createProcessor(forms: $forms);

        $content   = '[form slug="contact"]';
        $processed = $processor->process($content);

        self::assertStringContainsString('type="text"', $processed);
        self::assertStringContainsString('type="email"', $processed);
        self::assertStringContainsString('textarea', $processed);
        self::assertStringContainsString('select', $processed);
        self::assertStringContainsString('type="radio"', $processed);
        self::assertStringContainsString('type="checkbox"', $processed);
    }

    public function testFormRendersFieldNameAttribute(): void
    {
        $forms = $this->createStub(FormRepositoryInterface::class);
        $form  = $this->makeForm();
        $forms->method('findBySlug')->willReturn($form);

        $processor = $this->createProcessor(forms: $forms);

        $content   = '[form slug="contact"]';
        $processed = $processor->process($content);

        self::assertStringContainsString('name="fields[name]"', $processed);
        self::assertStringContainsString('name="fields[message]"', $processed);
    }

    public function testFormFieldWithNoNameReturnsEmpty(): void
    {
        $forms = $this->createStub(FormRepositoryInterface::class);
        $form  = new Form('Empty', new Slug('empty'));
        $form->setFields([
            ['type' => 'text', 'label' => 'No Name', 'width' => '100'],
        ]);
        $forms->method('findBySlug')->willReturn($form);

        $processor = $this->createProcessor(forms: $forms);

        $content   = '[form slug="empty"]';
        $processed = $processor->process($content);

        self::assertStringNotContainsString('No Name', $processed);
    }

    public function testFormStaticTextFieldRenders(): void
    {
        $forms = $this->createStub(FormRepositoryInterface::class);
        $form  = new Form('Static', new Slug('static'));
        $form->setFields([
            ['type' => 'static_text', 'label' => '', 'name' => '', 'width' => '100'],
        ]);
        $forms->method('findBySlug')->willReturn($form);

        $processor = $this->createProcessor(forms: $forms);

        $content   = '[form slug="static"]';
        $processed = $processor->process($content);

        self::assertStringContainsString('vl-field-col', $processed);
    }

    public function testFormFieldsKeepConfiguredWidthWhenEmbedded(): void
    {
        $forms = $this->createStub(FormRepositoryInterface::class);
        $form  = $this->makeForm();
        $form->setFields([
            ['type' => 'text', 'label' => 'Name', 'name' => 'name', 'width' => '50'],
            ['type' => 'text', 'label' => 'City', 'name' => 'city', 'width' => '33'],
            ['type' => 'text', 'label' => 'Zip', 'name' => 'zip', 'width' => '25'],
            ['type' => 'textarea', 'label' => 'Message', 'name' => 'message', 'width' => '100'],
        ]);
        $forms->method('findBySlug')->willReturn($form);

        $processor = $this->createProcessor(forms: $forms);
        $processed = $processor->process('[form slug="contact"]');

        self::assertStringContainsString('vl-field-col w-full md:w-1/2', $processed);
        self::assertStringContainsString('vl-field-col w-full md:w-1/3', $processed);
        self::assertStringContainsString('vl-field-col w-full md:w-1/4', $processed);
        self::assertStringContainsString('vl-field-col w-full px-2.5', $processed);
    }

    public function testFormFieldWidthIsHonouredForIntegerAndStringValues(): void
    {
        $forms = $this->createStub(FormRepositoryInterface::class);
        $form  = $this->makeForm();
        $form->setFields([
            ['type' => 'text', 'label' => 'Half', 'name' => 'half', 'width' => 50],
            ['type' => 'text', 'label' => 'Third', 'name' => 'third', 'width' => '33'],
            ['type' => 'text', 'label' => 'Unknown', 'name' => 'unknown', 'width' => '70'],
        ]);
        $forms->method('findBySlug')->willReturn($form);

        $processor = $this->createProcessor(forms: $forms);
        $processed = $processor->process('[form slug="contact"]');

        self::assertStringContainsString('vl-field-col w-full md:w-1/2', $processed);
        self::assertStringContainsString('vl-field-col w-full md:w-1/3', $processed);
        self::assertSame(1, substr_count($processed, 'vl-field-col w-full px-2.5'));
    }

    public function testFormFieldsDoNotStretchToFillTheRow(): void
    {
        $forms = $this->createStub(FormRepositoryInterface::class);
        $form  = $this->makeForm();
        $form->setFields([
            ['type' => 'email', 'label' => 'Email', 'name' => 'email', 'width' => '50'],
        ]);
        $forms->method('findBySlug')->willReturn($form);

        $processor = $this->createProcessor(forms: $forms);
        $processed = $processor->process('[form slug="contact"]');

        self::assertStringNotContainsString('flex-grow', $processed);
        self::assertStringNotContainsString('flex:1 1', $processed);
        self::assertStringContainsString('<div class="flex flex-wrap -mx-2.5">', $processed);
    }

    // ── Newsletter Form ─────────────────────────────────────

    public function testFormFieldRendersPlaceholder(): void
    {
        $forms = $this->createStub(FormRepositoryInterface::class);
        $form  = new Form('Placeholder', new Slug('placeholder'));
        $form->setFields([
            ['type' => 'text', 'label' => 'Name', 'name' => 'name', 'placeholder' => 'Your name', 'width' => '100'],
            ['type' => 'textarea', 'label' => 'Message', 'name' => 'message', 'placeholder' => 'Tell us "everything"', 'width' => '100'],
            ['type' => 'email', 'label' => 'Email', 'name' => 'email', 'width' => '100'],
        ]);
        $forms->method('findBySlug')->willReturn($form);

        $processor = $this->createProcessor(forms: $forms);
        $processed = $processor->process('[form slug="placeholder"]');

        self::assertStringContainsString('placeholder="Your name"', $processed);
        self::assertStringContainsString('placeholder="Tell us &quot;everything&quot;"', $processed);
        self::assertSame(2, substr_count($processed, 'placeholder='));
    }

    public function testFormOptionsRenderTitles(): void
    {
        $forms = $this->createStub(FormRepositoryInterface::class);
        $form  = new Form('Titles', new Slug('titles'));
        $form->setFields([
            ['type' => 'select', 'label' => 'Topic', 'name' => 'topic', 'options' => [
                ['value' => 'General', 'title' => 'General inquiries'],
                ['value' => 'Support', 'title' => 'Get help'],
            ], 'width' => '100'],
            ['type' => 'radio', 'label' => 'Priority', 'name' => 'priority', 'options' => [
                ['value' => 'Low', 'title' => 'No rush'],
                'High',
            ], 'width' => '100'],
            ['type' => 'checkbox', 'label' => 'Extras', 'name' => 'extras', 'options' => [
                ['value' => 'Gift wrap', 'title' => 'Adds 2 days'],
            ], 'width' => '100'],
        ]);
        $forms->method('findBySlug')->willReturn($form);

        $processor = $this->createProcessor(forms: $forms);
        $processed = $processor->process('[form slug="titles"]');

        self::assertStringContainsString('<option value="General" title="General inquiries">General</option>', $processed);
        self::assertStringContainsString('<option value="Support" title="Get help">Support</option>', $processed);
        self::assertStringContainsString('<label class="flex items-center gap-2 text-sm" title="No rush">', $processed);
        self::assertStringContainsString('<label class="flex items-center gap-2 text-sm" title="Adds 2 days">', $processed);
        self::assertStringNotContainsString('title=""', $processed);
    }

    public function testFormOptionsLegacyStringsStillRender(): void
    {
        $forms = $this->createStub(FormRepositoryInterface::class);
        $form  = $this->makeForm();
        $forms->method('findBySlug')->willReturn($form);

        $processor = $this->createProcessor(forms: $forms);
        $processed = $processor->process('[form slug="contact"]');

        self::assertStringContainsString('<option value="General">General</option>', $processed);
        self::assertStringContainsString('value="High"', $processed);
        self::assertStringNotContainsString('title=', $processed);
    }

    public function testNewsletterFormDisabled(): void
    {
        $settings  = $this->createSettings(['newsletter_enabled' => '0']);
        $processor = $this->createProcessor(settings: $settings);

        $content   = '[newsletter_form]';
        $processed = $processor->process($content);

        self::assertSame('', $processed);
    }

    public function testNewsletterFormWithCustomAttributes(): void
    {
        $csrf = $this->createStub(CsrfManager::class);
        $csrf->method('getToken')->willReturn('nltok');

        $processor = $this->createProcessor(csrf: $csrf);

        $content   = '[newsletter_form placeholder="Enter email" button="Go" tag="newsletter"]';
        $processed = $processor->process($content);

        self::assertStringContainsString('vl-newsletter-form', $processed);
        self::assertStringContainsString('Enter email', $processed);
        self::assertStringContainsString('Go</button>', $processed);
        self::assertStringContainsString('name="tag" value="newsletter"', $processed);
        self::assertStringContainsString('name="_csrf_token" value="nltok"', $processed);
        self::assertTrue($processor->hadShortcodes());
    }

    public function testNewsletterFormButtonTextWithApostrophe(): void
    {
        $csrf = $this->createStub(CsrfManager::class);
        $csrf->method('getToken')->willReturn('nltok');

        $processor = $this->createProcessor(csrf: $csrf);

        $content   = "[newsletter_form placeholder=\"Enter email\" button=\"Get to know when it's out\"]";
        $processed = $processor->process($content);

        // Apostrophe is preserved and HTML-escaped (&#039;) in the rendered button
        self::assertStringContainsString('Get to know when it&#039;s out</button>', $processed);
        self::assertStringNotContainsString('Get to know when it</button>', $processed);
    }

    public function testNewsletterFormAttributesWithQuoteEntities(): void
    {
        $csrf = $this->createStub(CsrfManager::class);
        $csrf->method('getToken')->willReturn('nltok');

        $processor = $this->createProcessor(csrf: $csrf);

        $content   = '[newsletter_form placeholder=&quot;Enter email&quot; button=&quot;Get to know when it&apos;s out&quot;]';
        $processed = $processor->process($content);

        self::assertStringContainsString('Get to know when it&#039;s out</button>', $processed);
        self::assertStringNotContainsString('Get to know when it</button>', $processed);
    }

    public function testNewsletterFormWithPrivacyPage(): void
    {
        $privacyPage = new Post('Privacy', new Slug('privacy'), 1);

        $posts = $this->createStub(PostRepositoryInterface::class);
        $posts->method('findBySlug')->willReturn($privacyPage);
        $posts->method('buildPagePath')->willReturn('privacy');

        $settings = $this->createSettings(['routing_page_prefix' => 'pages']);

        $processor = $this->createProcessor(posts: $posts, settings: $settings);

        $content   = '[newsletter_form privacy_page="privacy"]';
        $processed = $processor->process($content);

        self::assertStringContainsString('privacy_accepted', $processed);
        self::assertStringContainsString('Privacy Policy', $processed);
    }

    public function testNewsletterFormPrivacyPageNotFound(): void
    {
        $posts = $this->createStub(PostRepositoryInterface::class);
        $posts->method('findBySlug')->willReturn(null);

        $processor = $this->createProcessor(posts: $posts);

        $content   = '[newsletter_form privacy_page="nonexistent"]';
        $processed = $processor->process($content);

        self::assertStringContainsString('vl-newsletter-form', $processed);
        self::assertStringNotContainsString('privacy_accepted', $processed);
    }

    public function testNewsletterFormWidthPercent(): void
    {
        $content   = '[newsletter_form width="50%"]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('vl-newsletter-form', $processed);
        self::assertStringContainsString(
            'style="max-width:50%;margin-left:auto;margin-right:auto;"',
            $processed,
        );
        self::assertStringContainsString('@media (max-width:639px)', $processed);
        self::assertStringContainsString('max-width:100%!important', $processed);
    }

    public function testNewsletterFormWidthPixels(): void
    {
        $content   = '[newsletter_form width="400px"]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('style="max-width:400px;', $processed);
    }

    public function testNewsletterFormWidthAutoRendersFullWidth(): void
    {
        $processed = $this->processor->process('[newsletter_form width="auto"]');

        self::assertStringContainsString('vl-newsletter-form', $processed);
        self::assertStringNotContainsString('max-width', $processed);
    }

    public function testNewsletterFormWithoutWidthRendersFullWidth(): void
    {
        $processed = $this->processor->process('[newsletter_form]');

        self::assertStringContainsString('vl-newsletter-form', $processed);
        self::assertStringNotContainsString('max-width', $processed);
    }

    public function testNewsletterFormWidthRejectsInvalidValues(): void
    {
        $processed = $this->processor->process(
            '[newsletter_form width="50%);color:red"]',
        );

        self::assertStringContainsString('vl-newsletter-form', $processed);
        self::assertStringNotContainsString('max-width', $processed);
        self::assertStringNotContainsString('color:red', $processed);
    }

    public function testNewsletterFormRendersMathCaptcha(): void
    {
        $processed = $this->processor->process('[newsletter_form captcha="math"]');

        self::assertStringContainsString('name="captcha_answer"', $processed);
        self::assertStringNotContainsString('name="captcha_type"', $processed);
    }

    public function testNewsletterFormRendersTurnstileWithAutoThemeAndFlexibleSize(): void
    {
        $settings = $this->createSettings([
            'turnstile_site_key'   => 'ts-key',
            'turnstile_secret_key' => 'ts-secret',
        ]);
        $processor = $this->createProcessor(settings: $settings);

        $processed = $processor->process('[newsletter_form captcha="turnstile"]');

        self::assertStringContainsString('class="cf-turnstile', $processed);
        self::assertStringContainsString('data-sitekey="ts-key"', $processed);
        self::assertStringContainsString('data-theme="auto"', $processed);
        self::assertStringContainsString('data-size="flexible"', $processed);
        self::assertStringNotContainsString('overflow-hidden', $processed);
        self::assertStringContainsString('overflow-x-auto', $processed);
    }

    public function testNewsletterFormRendersHcaptchaWithoutAutoTheme(): void
    {
        $settings = $this->createSettings([
            'hcaptcha_site_key'   => 'hc-key',
            'hcaptcha_secret_key' => 'hc-secret',
        ]);
        $processor = $this->createProcessor(settings: $settings);

        $processed = $processor->process('[newsletter_form captcha="hcaptcha"]');

        self::assertStringContainsString('class="h-captcha', $processed);
        self::assertStringContainsString('data-sitekey="hc-key"', $processed);
        // hCaptcha only supports light|dark themes — auto is invalid.
        self::assertStringNotContainsString('data-theme="auto"', $processed);
        self::assertStringNotContainsString('overflow-hidden', $processed);
        self::assertStringContainsString('overflow-x-auto', $processed);
    }

    public function testNewsletterFormRendersHoneypotCaptcha(): void
    {
        $processed = $this->processor->process('[newsletter_form captcha="honeypot"]');

        self::assertStringNotContainsString('name="captcha_type"', $processed);
        self::assertStringContainsString('name="_hp_name"', $processed);
    }

    public function testNewsletterFormRendersCustomHoneypotName(): void
    {
        $processed = $this->processor->process('[newsletter_form captcha="honeypot" honeypot_name="company_website"]');

        self::assertStringContainsString('name="company_website"', $processed);
        self::assertStringNotContainsString('name="_hp_name"', $processed);
    }

    public function testNewsletterFormSanitizesHoneypotName(): void
    {
        $processed = $this->processor->process('[newsletter_form captcha="honeypot" honeypot_name="my field!"]');

        self::assertStringContainsString('name="myfield"', $processed);
    }

    public function testNewsletterFormStoresCaptchaTypeInSession(): void
    {
        $session = $this->createStub(SessionManager::class);
        $stored  = [];
        $session->method('set')->willReturnCallback(
            static function (string $key, mixed $value) use (&$stored): void {
                $stored[$key] = $value;
            }
        );

        $processor = $this->createProcessor(session: $session);
        $processor->process('[newsletter_form captcha="turnstile" tag="promo"]');

        self::assertSame('turnstile', $stored['newsletter_captcha_type'] ?? null);
    }

    public function testNewsletterFormStoresDisabledTypeInSession(): void
    {
        $session = $this->createStub(SessionManager::class);
        $stored  = [];
        $session->method('set')->willReturnCallback(
            static function (string $key, mixed $value) use (&$stored): void {
                $stored[$key] = $value;
            }
        );

        $processor = $this->createProcessor(session: $session);
        $processor->process('[newsletter_form]');

        self::assertSame('disabled', $stored['newsletter_captcha_type'] ?? null);
    }

    public function testNewsletterFormRendersMisconfiguredNoticeWhenTurnstileMissingKeys(): void
    {
        $settings  = $this->createSettings([
            'turnstile_site_key'   => '',
            'turnstile_secret_key' => '',
        ]);
        $processor = $this->createProcessor(settings: $settings);

        $processed = $processor->process('[newsletter_form captcha="turnstile"]');

        self::assertStringContainsString(
            'This form will not be shown because captcha is not configured.',
            $processed
        );
        self::assertStringNotContainsString('<form', $processed);
    }

    // ── Card Grid ─────────────────────

    public function testCardGroupRendersEqualWidthGrid(): void
    {
        $content   = '[card_group gap="12"]'
            . '[card_item][card_title]First[/card_title]'
            . '[card_desc]Desc one[/card_desc][/card_item]'
            . '[card_item][card_title]Second[/card_title]'
            . '[card_desc]Desc two[/card_desc][/card_item]'
            . '[/card_group]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('vl-card-group', $processed);
        self::assertStringContainsString('display:flex', $processed);
        self::assertStringContainsString('flex-wrap:wrap', $processed);
        self::assertStringContainsString('justify-content:center', $processed);
        self::assertStringContainsString('flex:0 1 260px', $processed);
        self::assertStringNotContainsString('flex:1 1 240px', $processed);
        self::assertStringContainsString('First', $processed);
        self::assertStringContainsString('Desc two', $processed);
        self::assertStringNotContainsString('[card_', $processed);
    }

    public function testCardGroupWithoutImagesOmitsImageSpacers(): void
    {
        $content   = '[card_group gap="12"]'
            . '[card_item][card_title]First[/card_title]'
            . '[card_desc]Desc one[/card_desc][/card_item]'
            . '[card_item][card_title]Second[/card_title]'
            . '[card_desc]Desc two[/card_desc][/card_item]'
            . '[/card_group]';
        $processed = $this->processor->process($content);

        self::assertStringNotContainsString('vl-card-image-empty', $processed);
        self::assertStringNotContainsString('aspect-ratio:16/9', $processed);
    }

    public function testCardGroupWithMixedImagesKeepsSpacers(): void
    {
        $content   = '[card_group gap="12"]'
            . '[card_item image="/uploads/a.jpg"][card_title]With image[/card_title]'
            . '[card_desc]Desc one[/card_desc][/card_item]'
            . '[card_item][card_title]No image[/card_title]'
            . '[card_desc]Desc two[/card_desc][/card_item]'
            . '[/card_group]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('vl-card-image-empty', $processed);
        self::assertStringContainsString('/uploads/a.jpg', $processed);
    }

    public function testCardGroupWholeCardLink(): void
    {
        $content   = '[card_group]'
            . '[card_item link="/pricing" target="_blank"]'
            . '[card_title]Plan[/card_title]'
            . '[card_desc]See <a href="/x">details</a>[/card_desc]'
            . '[/card_item][/card_group]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('<a class="vl-card" href="/pricing"', $processed);
        self::assertStringContainsString('target="_blank" rel="noopener"', $processed);
        self::assertStringContainsString('See details', $processed);
        self::assertStringNotContainsString('<a href="/x">', $processed);
    }

    public function testCardGroupRejectsUnsafeValues(): void
    {
        $content   = '[card_group bg="red" radius="99em"]'
            . '[card_item image="javascript:alert(1)" bg="#zzzzzz" link="data:text/html,x"]'
            . '[card_title]T[/card_title]'
            . '[card_desc]D[/card_desc][/card_item][/card_group]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('vl-card-group', $processed);
        self::assertStringNotContainsString('javascript:', $processed);
        self::assertStringNotContainsString('data:text', $processed);
        self::assertStringNotContainsString('<img', $processed);
        self::assertStringNotContainsString('99em', $processed);
        self::assertStringContainsString('border-radius:12px', $processed);
    }

    public function testCardGroupWidth(): void
    {
        $processed = $this->processor->process(
            '[card_group width="50%"][card_item][card_title]T[/card_title]'
            . '[card_desc]D[/card_desc][/card_item][/card_group]',
        );

        self::assertStringContainsString('max-width:50%', $processed);
        self::assertStringContainsString('margin-left:auto', $processed);
    }

    public function testCardGroupSkipsEmptyCards(): void
    {
        $content   = '[card_group]'
            . '[card_item][card_title][/card_title][card_desc][/card_desc][/card_item]'
            . '[/card_group]';
        $processed = $this->processor->process($content);

        self::assertStringNotContainsString('vl-card-group', $processed);
        self::assertStringNotContainsString('[card_', $processed);
    }

    public function testCardGroupLightbox(): void
    {
        $content   = '[card_group lightbox="true"]'
            . '[card_item image="/media/a.jpg"][card_title]T[/card_title]'
            . '[card_desc]D[/card_desc][/card_item][/card_group]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('data-lightbox="true"', $processed);
        self::assertStringContainsString('data-lightbox-src="/media/a.jpg"', $processed);
        self::assertStringContainsString('cursor:pointer', $processed);
        self::assertStringContainsString('title="Click to enlarge"', $processed);
        self::assertStringContainsString('lightbox-open', $processed);
    }

    public function testCardGroupLightboxDefaultsOff(): void
    {
        $content   = '[card_group]'
            . '[card_item image="/media/a.jpg"][card_title]T[/card_title]'
            . '[card_desc]D[/card_desc][/card_item][/card_group]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('<img', $processed);
        self::assertStringNotContainsString('data-lightbox', $processed);
        self::assertStringNotContainsString('lightbox-open', $processed);
    }

    public function testCardGroupImageAspectAndEmptyPlaceholder(): void
    {
        $content   = '[card_group]'
            . '[card_item image="/media/a.jpg"][card_title]T[/card_title]'
            . '[card_desc]D[/card_desc][/card_item]'
            . '[card_item][card_title]U[/card_title]'
            . '[card_desc]E[/card_desc][/card_item][/card_group]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('aspect-ratio:16/9', $processed);
        self::assertStringContainsString('object-fit:cover', $processed);
        self::assertStringContainsString('vl-card-image-empty', $processed);
    }

    public function testCardGroupEqualHeightBodiesAndPinnedDesc(): void
    {
        $content   = '[card_group]'
            . '[card_item link="/x"][card_title]T[/card_title]'
            . '[card_desc]D[/card_desc][/card_item]'
            . '[card_item][card_title]U[/card_title]'
            . '[card_desc]E[/card_desc][/card_item][/card_group]';
        $processed = $this->processor->process($content);

        self::assertSame(2, substr_count($processed, 'flex-direction:column;height:100%'));
        self::assertStringContainsString('margin-top:auto', $processed);
    }

    public function testCardGroupHalign(): void
    {
        $content   = '[card_group width="50%" halign="right"]'
            . '[card_item][card_title]T[/card_title]'
            . '[card_desc]D[/card_desc][/card_item][/card_group]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('max-width:50%', $processed);
        self::assertStringContainsString('margin-left:auto;margin-right:0', $processed);
    }

    public function testCardGroupHalignDefaultsCenter(): void
    {
        $content   = '[card_group width="50%"]'
            . '[card_item][card_title]T[/card_title]'
            . '[card_desc]D[/card_desc][/card_item][/card_group]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('margin-left:auto;margin-right:auto', $processed);
    }

    // ── Card Grid (video) ─────────────────────────────────
    public function testCardGroupRendersVideoWhenImageIsVideo(): void
    {
        $content   = '[card_group]'
            . '[card_item image="/media/clip.mp4"][card_title]T[/card_title]'
            . '[card_desc]D[/card_desc][/card_item][/card_group]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('<video', $processed);
        self::assertStringContainsString('src="/media/clip.mp4"', $processed);
        self::assertStringContainsString('controls', $processed);
        self::assertStringNotContainsString('<img', $processed);
    }

    public function testCardGroupVideoFitContain(): void
    {
        $content   = '[card_group]'
            . '[card_item image="/media/clip.mp4" fit="contain"][card_title]T[/card_title]'
            . '[card_desc]D[/card_desc][/card_item][/card_group]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('object-fit:contain', $processed);
    }

    public function testCardGroupVideoAutoplay(): void
    {
        $content   = '[card_group]'
            . '[card_item image="/media/clip.mp4" autoplay="true"][card_title]T[/card_title]'
            . '[card_desc]D[/card_desc][/card_item][/card_group]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('autoplay muted', $processed);
        self::assertStringNotContainsString('controls', $processed);
    }

    public function testCardGroupImageStillRendersImg(): void
    {
        $content   = '[card_group]'
            . '[card_item image="/media/a.jpg"][card_title]T[/card_title]'
            . '[card_desc]D[/card_desc][/card_item][/card_group]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('<img', $processed);
        self::assertStringNotContainsString('<video', $processed);
    }

    // ── Hero (video) ────────────────────────────────────────
    public function testHeroRendersVideoWhenImageIsVideo(): void
    {
        $content   = '[hero image="/media/clip.mp4"][hero_heading]H[/hero_heading][hero_subtitle]S[/hero_subtitle][/hero]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('<video', $processed);
        self::assertStringContainsString('src="/media/clip.mp4"', $processed);
        self::assertStringContainsString('controls', $processed);
    }

    public function testHeroRendersVideoAutoplay(): void
    {
        $content   = '[hero image="/media/clip.mp4" mediaAutoplay="true"][hero_heading]H[/hero_heading][hero_subtitle]S[/hero_subtitle][/hero]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('autoplay muted', $processed);
    }

    public function testHeroImageStillRendersImg(): void
    {
        $content   = '[hero image="/media/a.jpg"][hero_heading]H[/hero_heading][hero_subtitle]S[/hero_subtitle][/hero]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('<img', $processed);
        self::assertStringNotContainsString('<video', $processed);
    }

    // ── TOC ─────────────────────────────────────────────────

    public function testTocNoHeadingsRemovesShortcode(): void
    {
        $content   = '[toc]<p>No headings here</p>';
        $processed = $this->processor->process($content);

        self::assertStringNotContainsString('[toc', $processed);
        self::assertStringNotContainsString('vl-toc', $processed);
    }

    public function testTocDuplicateHeadingIds(): void
    {
        $content   = '[toc]<h2>Same</h2><h2>Same</h2>';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('id="same"', $processed);
        self::assertStringContainsString('id="same-1"', $processed);
    }

    public function testTocHeadingWithExistingIdNotOverridden(): void
    {
        $content   = '[toc]<h2 id="custom">Title</h2>';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('id="custom"', $processed);
    }

    public function testTocMaxLevelOneOnlyH1(): void
    {
        $content   = '[toc max_level="1"]<h1>H1</h1><h2>H2</h2>';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('href="#h1"', $processed);
        self::assertStringNotContainsString('href="#h2"', $processed);
    }

    public function testTocGeneratesNavElement(): void
    {
        $content   = '[toc]<h2>Section</h2>';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('<nav class="vl-toc"', $processed);
        self::assertStringContainsString('aria-label="Table of Contents"', $processed);
        self::assertStringContainsString('vl-toc-list', $processed);
        self::assertStringContainsString('vl-toc-item', $processed);
    }

    public function testTocH2OnlyStartsAtFirstColumn(): void
    {
        $content   = '[toc max_level="2"]<h2>Content Management</h2><h2>E-Commerce</h2>';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('vl-toc-h1', $processed);
        self::assertStringNotContainsString('vl-toc-h2', $processed);
    }

    public function testTocH2AndH3IndentedRelatively(): void
    {
        $content   = '[toc]<h2>Section</h2><h3>Subsection</h3>';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('vl-toc-item vl-toc-h1"><a href="#section"', $processed);
        self::assertStringContainsString(
            'vl-toc-item vl-toc-h2"><a href="#subsection"',
            $processed,
        );
        self::assertStringNotContainsString('vl-toc-h3', $processed);
    }

    public function testTocFullHierarchyKeepsAbsoluteClasses(): void
    {
        $content   = '[toc]<h1>Title</h1><h2>Section</h2><h3>Subsection</h3>';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('vl-toc-item vl-toc-h1"><a href="#title"', $processed);
        self::assertStringContainsString('vl-toc-item vl-toc-h2"><a href="#section"', $processed);
        self::assertStringContainsString(
            'vl-toc-item vl-toc-h3"><a href="#subsection"',
            $processed,
        );
    }

    public function testTocHeadingEntitiesAreNotDoubleEncoded(): void
    {
        $content   = '[toc]<h2>Newsletter &amp; Marketing</h2>';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('>Newsletter &amp; Marketing</a>', $processed);
        self::assertStringNotContainsString('&amp;amp;', $processed);
        self::assertStringContainsString('href="#newsletter-marketing"', $processed);
        self::assertStringContainsString('id="newsletter-marketing"', $processed);
    }

    // ── Pages ───────────────────────────────────────────────

    public function testPagesWithChildren(): void
    {
        $child = new Post('Child Page', new Slug('child'), 1);

        $posts = $this->createStub(PostRepositoryInterface::class);
        $posts->method('findPagesByParent')->willReturn([$child]);
        $posts->method('buildPagePath')->willReturn('child');

        $processor = $this->createProcessor(posts: $posts);

        $content   = '[pages]';
        $processed = $processor->process($content);

        self::assertStringContainsString('vl-pages-list', $processed);
        self::assertStringContainsString('Child Page', $processed);
        self::assertStringContainsString('/child', $processed);
    }

    public function testPagesNoChildrenRemovesShortcode(): void
    {
        $posts = $this->createStub(PostRepositoryInterface::class);
        $posts->method('findPagesByParent')->willReturn([]);

        $processor = $this->createProcessor(posts: $posts);

        $content   = '<p>[pages]</p>';
        $processed = $processor->process($content);

        self::assertStringNotContainsString('[pages', $processed);
    }

    public function testPagesWithParentSlug(): void
    {
        $parent = new Post('Parent', new Slug('parent'), 1);
        $parent->setId(5);
        $child  = new Post('Child', new Slug('child'), 1);

        $posts = $this->createStub(PostRepositoryInterface::class);
        $posts->method('findBySlug')->willReturn($parent);
        $posts->method('findPagesByParent')->willReturn([$child]);
        $posts->method('buildPagePath')->willReturn('child');

        $processor = $this->createProcessor(posts: $posts);

        $content   = '[pages parent="parent"]';
        $processed = $processor->process($content);

        self::assertStringContainsString('vl-pages-list', $processed);
        self::assertStringContainsString('Child', $processed);
    }

    public function testPagesParentNotFound(): void
    {
        $posts = $this->createStub(PostRepositoryInterface::class);
        $posts->method('findBySlug')->willReturn(null);
        $posts->method('findPagesByParent')->willReturn([]);

        $processor = $this->createProcessor(posts: $posts);

        $content   = '[pages parent="missing"]';
        $processed = $processor->process($content);

        self::assertStringNotContainsString('[pages', $processed);
    }

    public function testPagesWithCurrentPost(): void
    {
        $post  = new Post('Parent', new Slug('parent'), 1);
        $post->setId(10);

        $child = new Post('Child', new Slug('child'), 1);

        $posts = $this->createStub(PostRepositoryInterface::class);
        $posts->method('findPagesByParent')->willReturn([$child]);
        $posts->method('buildPagePath')->willReturn('child');

        $processor = $this->createProcessor(posts: $posts);

        $content   = '[pages]';
        $processed = $processor->process($content, $post);

        self::assertStringContainsString('vl-pages-list', $processed);
    }

    public function testPagesWithPagePrefix(): void
    {
        $child = new Post('Child', new Slug('child'), 1);

        $posts = $this->createStub(PostRepositoryInterface::class);
        $posts->method('findPagesByParent')->willReturn([$child]);
        $posts->method('buildPagePath')->willReturn('child');

        $settings = $this->createSettings(['routing_page_prefix' => 'wiki']);

        $processor = $this->createProcessor(posts: $posts, settings: $settings);

        $content   = '[pages]';
        $processed = $processor->process($content);

        self::assertStringContainsString('/wiki/', $processed);
    }

    public function testPagesMaxLevelTwo(): void
    {
        $child = new Post('Child', new Slug('child'), 1);
        $child->setId(2);
        $grandchild = new Post('Grandchild', new Slug('grandchild'), 1);

        $posts = $this->createStub(PostRepositoryInterface::class);
        $posts->method('findPagesByParent')
            ->willReturnCallback(function (?int $parentId) use ($child, $grandchild) {
                return $parentId === null ? [$child] : [$grandchild];
            });
        $posts->method('buildPagePath')
            ->willReturnCallback(fn (Post $p) => (string) $p->getSlug());

        $processor = $this->createProcessor(posts: $posts);

        $content   = '[pages max_level="2"]';
        $processed = $processor->process($content);

        self::assertStringContainsString('vl-pages-list', $processed);
        self::assertStringContainsString('Child', $processed);
        self::assertStringContainsString('Grandchild', $processed);
    }

    // ── Meta Fields ─────────────────────────────────────────

    public function testFieldShortcodeResolvesMetaValue(): void
    {
        $post = new Post('Title', new Slug('title'), 1);
        $post->setMeta(['color' => 'red']);

        $content   = 'Color: [field name="color"]';
        $processed = $this->processor->process($content, $post);

        self::assertStringContainsString('Color: red', $processed);
    }

    public function testFieldShortcodeWithoutPostRemovesShortcode(): void
    {
        $content   = 'Value: [field name="test"]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('Value: ', $processed);
        self::assertStringNotContainsString('[field', $processed);
    }

    public function testFieldShortcodeWithMediaIdRendersImg(): void
    {
        $media = new Media(1, 'photo.jpg', 'photo.jpg', 'image/jpeg', 5000, '2026/photo.jpg');

        $mediaRepo = $this->createStub(MediaRepositoryInterface::class);
        $mediaRepo->method('findById')->willReturn($media);

        $processor = $this->createProcessor(media: $mediaRepo);

        $post = new Post('Title', new Slug('title'), 1);
        $post->setMeta(['image' => '42']);

        $content   = '[field name="image"]';
        $processed = $processor->process($content, $post);

        self::assertStringContainsString('<img', $processed);
        self::assertStringContainsString('2026/photo.jpg', $processed);
    }

    public function testFieldShortcodeMediaIdNotFoundRendersValue(): void
    {
        $mediaRepo = $this->createStub(MediaRepositoryInterface::class);
        $mediaRepo->method('findById')->willReturn(null);

        $processor = $this->createProcessor(media: $mediaRepo);

        $post = new Post('Title', new Slug('title'), 1);
        $post->setMeta(['image' => '999']);

        $content   = '[field name="image"]';
        $processed = $processor->process($content, $post);

        self::assertStringContainsString('999', $processed);
        self::assertStringNotContainsString('<img', $processed);
    }

    public function testFieldShortcodeEscapesHtml(): void
    {
        $post = new Post('Title', new Slug('title'), 1);
        $post->setMeta(['html' => '<script>alert(1)</script>']);

        $content   = '[field name="html"]';
        $processed = $this->processor->process($content, $post);

        self::assertStringContainsString('&lt;script&gt;', $processed);
        self::assertStringNotContainsString('<script>', $processed);
    }

    public function testFieldShortcodeWithQuoteEncodedAttributes(): void
    {
        $post = new Post('Title', new Slug('title'), 1);
        $post->setMeta(['name' => 'John']);

        $content   = '[field name=&quot;name&quot;]';
        $processed = $this->processor->process($content, $post);

        self::assertStringContainsString('John', $processed);
    }

    // ── Bare Meta Fields ────────────────────────────────────

    public function testBareMetaFieldsMultiple(): void
    {
        $post = new Post('Title', new Slug('title'), 1);
        $post->setMeta(['a' => 'Alpha', 'b' => 'Beta']);

        $content   = '{{a}} and {{b}}';
        $processed = $this->processor->process($content, $post);

        self::assertSame('Alpha and Beta', $processed);
    }

    public function testBareMetaFieldsWithoutPost(): void
    {
        $content   = '{{test}}';
        $processed = $this->processor->process($content);

        self::assertSame('{{test}}', $processed);
    }

    // ── Ad ──────────────────────────────────────────────────

    public function testAdEnabledEmptyCodeRemovesShortcode(): void
    {
        $settings = $this->createSettings([
            'advertisement_enabled' => '1',
            'advertisement_code'    => '',
        ]);

        $processor = $this->createProcessor(settings: $settings);

        $content   = '<p>[ad]</p>';
        $processed = $processor->process($content);

        self::assertStringNotContainsString('[ad]', $processed);
    }

    public function testAdBareWithoutPTag(): void
    {
        $settings = $this->createSettings([
            'advertisement_enabled' => '1',
            'advertisement_code'    => '<div>AD</div>',
        ]);

        $processor = $this->createProcessor(settings: $settings);

        $content   = 'Before [ad] After';
        $processed = $processor->process($content);

        self::assertStringContainsString('<div>AD</div>', $processed);
    }

    // ── Carousel ────────────────────────────────────────────

    public function testEmbedYoutubeShortcode(): void
    {
        $content   = '[embed]https://www.youtube.com/watch?v=rwU6RgnkVbc[/embed]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('vl-video-embed', $processed);
        self::assertStringContainsString('https://www.youtube.com/embed/rwU6RgnkVbc', $processed);
        self::assertStringNotContainsString('[embed]', $processed);
    }

    public function testEmbedParagraphWrappedShortcode(): void
    {
        $content   = '<p>[embed]https://youtu.be/rwU6RgnkVbc[/embed]</p>';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('https://www.youtube.com/embed/rwU6RgnkVbc', $processed);
        self::assertStringNotContainsString('[embed]', $processed);
    }

    public function testEmbedYoutubeEmbedUrlForm(): void
    {
        $content   = '[embed]https://www.youtube.com/embed/rwU6RgnkVbc[/embed]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('https://www.youtube.com/embed/rwU6RgnkVbc', $processed);
        self::assertStringNotContainsString('[embed]', $processed);
    }

    public function testEmbedVimeoShortcode(): void
    {
        $content   = '[embed]https://vimeo.com/123456789[/embed]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('https://player.vimeo.com/video/123456789', $processed);
    }

    public function testEmbedNonVideoUrlFallsBackToLink(): void
    {
        $content   = '[embed]https://example.com/page[/embed]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('<a href="https://example.com/page">', $processed);
        self::assertStringNotContainsString('<iframe', $processed);
    }

    public function testBareYoutubeIframeGetsWrapped(): void
    {
        $content   = '<p>Find anything.</p>'
            . '<iframe src="https://www.youtube.com/embed/rwU6RgnkVbc" style="position:absolute;top:0;left:0;width:100%;height:100%;border:0" '
            . 'allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>'
            . '<p>More text</p>';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('vl-video-embed', $processed);
        self::assertStringContainsString('https://www.youtube.com/embed/rwU6RgnkVbc', $processed);
        self::assertStringContainsString('position:relative;padding-bottom:56.25%', $processed);
    }

    public function testBareIframeInParagraphGetsWrappedWithoutNestedP(): void
    {
        $content   = '<p><iframe src="https://www.youtube.com/embed/rwU6RgnkVbc"></iframe></p>';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('vl-video-embed', $processed);
        self::assertStringNotContainsString('<p><div', $processed);
    }

    public function testAlreadyWrappedIframeIsNotDoubleWrapped(): void
    {
        $content   = '<div class="vl-video-embed" style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;max-width:100%;margin:1.5rem 0">'
            . '<iframe src="https://www.youtube.com/embed/rwU6RgnkVbc" style="position:absolute;top:0;left:0;width:100%;height:100%;border:0" '
            . 'allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>'
            . '</div>';
        $processed = $this->processor->process($content);

        self::assertSame(1, substr_count($processed, 'vl-video-embed'));
    }

    public function testBareIframeWithUnsafeSrcIsLeftAlone(): void
    {
        $content   = '<p>Text</p><iframe src="javascript:alert(1)"></iframe>';
        $processed = $this->processor->process($content);

        self::assertStringNotContainsString('vl-video-embed', $processed);
        self::assertStringContainsString('javascript:alert(1)', $processed);
    }

    public function testCarouselWithDotsAndArrows(): void
    {
        $content   = '[carousel images="https://a.jpg,https://b.jpg" dots="true" arrows="true"]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('vl-cdot', $processed);
        self::assertStringContainsString('goTo(0)', $processed);
        self::assertStringContainsString('goTo(1)', $processed);
        self::assertStringContainsString('prev()', $processed);
        self::assertStringContainsString('next()', $processed);
        self::assertStringContainsString('aria-label="Previous slide"', $processed);
        self::assertStringContainsString('aria-label="Next slide"', $processed);
        self::assertStringContainsString('aria-label="Go to slide 1"', $processed);
        self::assertStringContainsString('aria-label="Go to slide 2"', $processed);
        self::assertStringContainsString(':aria-current="active === 0', $processed);
    }

    public function testCarouselControlsHaveHoverTitles(): void
    {
        $content   = '[carousel images="https://a.jpg,https://b.jpg" dots="true" arrows="true" pauseBtn="true"]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('title="Previous slide"', $processed);
        self::assertStringContainsString('title="Next slide"', $processed);
        self::assertStringContainsString('title="Go to slide 1"', $processed);
        self::assertStringContainsString(':title="paused ?', $processed);
    }

    public function testCarouselWithoutDotsAndArrows(): void
    {
        $content   = '[carousel images="https://a.jpg,https://b.jpg" dots="false" arrows="false"]';
        $processed = $this->processor->process($content);

        self::assertStringNotContainsString('vl-cdot', $processed);
        self::assertStringNotContainsString('vl-c-arrow', $processed);
    }

    public function testCarouselSingleImageNoControls(): void
    {
        $content   = '[carousel images="https://a.jpg" dots="true" arrows="true"]';
        $processed = $this->processor->process($content);

        self::assertStringNotContainsString('vl-cdot', $processed);
        self::assertStringNotContainsString('vl-c-arrow', $processed);
    }

    public function testCarouselWithPauseButton(): void
    {
        $content   = '[carousel images="https://a.jpg,https://b.jpg" pauseBtn="true"]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('togglePause()', $processed);
        self::assertStringContainsString(':aria-label="paused ?', $processed);
        self::assertStringContainsString('Pause carousel', $processed);
        self::assertStringContainsString('Play carousel', $processed);
    }

    public function testCarouselAutoplayFalse(): void
    {
        $content   = '[carousel images="https://a.jpg" autoplay="false"]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('autoplay:false', $processed);
    }

    public function testCarouselCustomDuration(): void
    {
        $content   = '[carousel images="https://a.jpg" duration="8000"]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('duration:8000', $processed);
    }

    public function testCarouselCustomHeight(): void
    {
        $content   = '[carousel images="https://a.jpg" height="400px"]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('height:400px', $processed);
    }

    public function testCarouselWithBgColor(): void
    {
        $content   = '[carousel images="https://a.jpg" bg="#ff0000"]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('background:#ff0000', $processed);
    }

    public function testCarouselDefaultFitValues(): void
    {
        foreach (['cover', 'contain', 'fill', 'none', 'scale-down'] as $fit) {
            $content   = '[carousel images="https://a.jpg" fit="' . $fit . '"]';
            $processed = $this->processor->process($content);

            self::assertStringContainsString('object-fit:' . $fit, $processed);
        }
    }

    public function testCarouselInvalidFitFallsBackToCover(): void
    {
        $content   = '[carousel images="https://a.jpg" fit="INVALID"]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('object-fit:cover', $processed);
    }

    public function testCarouselWithLightbox(): void
    {
        $content   = '[carousel images="https://a.jpg" lightbox="true"]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('openLightbox(', $processed);
        self::assertStringContainsString('cursor:pointer', $processed);
    }

    public function testCarouselWithoutLightboxNoOverlay(): void
    {
        $content   = '[carousel images="https://a.jpg" lightbox="false"]';
        $processed = $this->processor->process($content);

        self::assertStringNotContainsString('openLightbox(', $processed);
    }

    public function testCarouselYouTubeVideoSlide(): void
    {
        $content   = '[carousel images="https://www.youtube.com/watch?v=dQw4w9WgXcQ"]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('iframe', $processed);
        self::assertStringContainsString('youtube.com/embed/dQw4w9WgXcQ', $processed);
    }

    public function testCarouselYouTubeShortUrl(): void
    {
        $content   = '[carousel images="https://youtu.be/dQw4w9WgXcQ"]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('iframe', $processed);
        self::assertStringContainsString('youtube.com/embed/dQw4w9WgXcQ', $processed);
    }

    public function testCarouselVimeoVideoSlide(): void
    {
        $content   = '[carousel images="https://vimeo.com/123456789"]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('iframe', $processed);
        self::assertStringContainsString('player.vimeo.com/video/123456789', $processed);
    }

    public function testCarouselLocalVideoSlide(): void
    {
        $content   = '[carousel images="https://example.com/clip.mp4" autoplay="true"]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('<video', $processed);
        self::assertStringContainsString('autoplay muted', $processed);
        self::assertStringContainsString('clip.mp4', $processed);
    }

    public function testCarouselLocalVideoAutoplayFalse(): void
    {
        $content   = '[carousel images="https://example.com/clip.mp4" autoplay="false"]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('<video', $processed);
        self::assertStringNotContainsString('autoplay muted', $processed);
        self::assertStringContainsString('clip.mp4', $processed);
    }

    public function testCarouselTransitionSlideLeft(): void
    {
        $content   = '[carousel images="https://a.jpg,https://b.jpg" transition="slide-left"]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('translate-x-full', $processed);
        self::assertStringContainsString('-translate-x-full', $processed);
    }

    public function testCarouselTransitionSlideRight(): void
    {
        $content   = '[carousel images="https://a.jpg,https://b.jpg" transition="slide-right"]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('-translate-x-full', $processed);
        self::assertStringContainsString('translate-x-full', $processed);
    }

    public function testCarouselTransitionSlideUp(): void
    {
        $content   = '[carousel images="https://a.jpg,https://b.jpg" transition="slide-up"]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('translate-y-full', $processed);
        self::assertStringContainsString('-translate-y-full', $processed);
    }

    public function testCarouselTransitionZoom(): void
    {
        $content   = '[carousel images="https://a.jpg,https://b.jpg" transition="zoom"]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('scale-75', $processed);
        self::assertStringContainsString('scale-110', $processed);
    }

    public function testCarouselTransitionNone(): void
    {
        $content   = '[carousel images="https://a.jpg,https://b.jpg" transition="none"]';
        $processed = $this->processor->process($content);

        self::assertStringNotContainsString('x-transition:enter', $processed);
    }

    public function testCarouselThemeDark(): void
    {
        $settings = $this->createSettings(['active_theme' => 'dark']);

        $processor = $this->createProcessor(settings: $settings);

        $content   = '[carousel images="https://a.jpg"]';
        $processed = $processor->process($content);

        self::assertStringContainsString('background:#1f2937', $processed);
    }

    public function testCarouselThemeVivid(): void
    {
        $settings = $this->createSettings(['active_theme' => 'vivid']);

        $processor = $this->createProcessor(settings: $settings);

        $content   = '[carousel images="https://a.jpg"]';
        $processed = $processor->process($content);

        self::assertStringContainsString('background:#f3f4f6', $processed);
    }

    public function testCarouselWrappedInPTag(): void
    {
        $content   = '<p>[carousel images="https://a.jpg"]</p>';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('vl-carousel', $processed);
        self::assertStringNotContainsString('<p>[carousel', $processed);
    }

    public function testCarouselLightboxOnlyRenderedOnce(): void
    {
        $content = '[carousel images="https://a.jpg,https://b.jpg" lightbox="true"]'
            . ' <img src="/uploads/test.jpg" data-lightbox="true">';

        $processed = $this->processor->process($content);

        $count = substr_count($processed, 'lbActive');
        self::assertGreaterThanOrEqual(2, $count);
        $overlayCount = substr_count($processed, '@lightbox-open.window');
        self::assertSame(1, $overlayCount);
    }

    public function testCarouselEmptyImageInListSkipped(): void
    {
        $content   = '[carousel images="https://a.jpg,,https://b.jpg"]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('vl-carousel', $processed);
        self::assertStringContainsString('https://a.jpg', $processed);
        self::assertStringContainsString('https://b.jpg', $processed);
    }

    // ── Link Button ─────────────────────────────────────────

    public function testLinkButtonFullWidth(): void
    {
        $content   = '[link_button href="/go" fullWidth="true"]Go[/link_button]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('justify-content: stretch', $processed);
        self::assertStringContainsString('width: 100%', $processed);
    }

    public function testLinkButtonAlignmentLeft(): void
    {
        $content   = '[link_button href="/go" alignment="left"]Go[/link_button]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('justify-content: flex-start', $processed);
    }

    public function testLinkButtonAlignmentCenter(): void
    {
        $content   = '[link_button href="/go" alignment="center"]Go[/link_button]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('justify-content: center', $processed);
    }

    public function testLinkButtonDefaultAlignment(): void
    {
        $content   = '[link_button href="/go"]Go[/link_button]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('justify-content: center', $processed);
    }

    public function testLinkButtonDefaultStyles(): void
    {
        $content   = '[link_button href="/go"]Go[/link_button]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('background-color: #000000', $processed);
        self::assertStringContainsString('color: #ffffff', $processed);
        self::assertStringContainsString('border: 0px none #000000', $processed);
        self::assertStringContainsString('border-radius: 4px', $processed);
        self::assertStringContainsString('padding: 10px 20px', $processed);
    }

    public function testLinkButtonWrappedInPTag(): void
    {
        $content   = '<p>[link_button href="/go"]Click[/link_button]</p>';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('vl-link-button-wrapper', $processed);
        self::assertStringNotContainsString('<p>[link_button', $processed);
    }

    public function testLinkButtonGroupVertical(): void
    {
        $content   = '[link_button_group layout="vertical" gap="16"]
[link_button href="/one"]One[/link_button]
[link_button href="/two" bgColor="#ff0000"]Two[/link_button]
[/link_button_group]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('class="vl-link-button-group"', $processed);
        self::assertStringContainsString('flex-direction: column', $processed);
        self::assertStringContainsString('gap: 16px', $processed);
        self::assertStringContainsString('href="/one"', $processed);
        self::assertStringContainsString('href="/two"', $processed);
        self::assertStringContainsString('background-color: #ff0000', $processed);
        self::assertStringNotContainsString('[link_button_group', $processed);
    }

    public function testLinkButtonGroupHorizontal(): void
    {
        $content   = '[link_button_group layout="horizontal" gap="8"]
[link_button href="/a"]A[/link_button]
[link_button href="/b"]B[/link_button]
[/link_button_group]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('flex-direction: row', $processed);
        self::assertStringContainsString('gap: 8px', $processed);
    }

    public function testLinkButtonGroupDefaults(): void
    {
        $content   = '<p>[link_button_group]
[link_button]Single[/link_button]
[/link_button_group]</p>';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('flex-direction: column', $processed);
        self::assertStringContainsString('gap: 12px', $processed);
        self::assertStringNotContainsString('<p>[link_button_group', $processed);
    }

    public function testLinkButtonGroupVerticalAlignment(): void
    {
        $content   = '[link_button_group layout="vertical"]
[link_button href="/left" alignment="left"]Left[/link_button]
[link_button href="/center" alignment="center"]Center[/link_button]
[link_button href="/right" alignment="right"]Right[/link_button]
[/link_button_group]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('justify-content:flex-start', $processed);
        self::assertStringContainsString('justify-content:center', $processed);
        self::assertStringContainsString('justify-content:flex-end', $processed);
        self::assertSame(3, substr_count($processed, 'vl-link-button-row'));
    }

    public function testLinkButtonGroupHorizontalAlignment(): void
    {
        $content   = '[link_button_group layout="horizontal"]
[link_button href="/a" alignment="left"]A[/link_button]
[link_button href="/b" alignment="right"]B[/link_button]
[/link_button_group]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('flex-direction: row', $processed);
        self::assertSame(2, substr_count($processed, 'vl-link-button-row'));
        self::assertStringContainsString('justify-content:flex-start', $processed);
        self::assertStringContainsString('justify-content:flex-end', $processed);
        self::assertStringContainsString('flex:1 1 0', $processed);
    }

    public function testLegacySingleLinkButtonStillRenders(): void
    {
        $content   = '[link_button href="/legacy"]Legacy[/link_button]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('vl-link-button-wrapper', $processed);
        self::assertStringContainsString('href="/legacy"', $processed);
    }

    // ── Product Embed ───────────────────────────────────────

    public function testProductEmbedWithValidProduct(): void
    {
        $product = $this->makeProduct();
        $product->setId(5);

        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findById')->willReturn($product);

        $processor = $this->createProcessor(products: $products);

        $content   = '[product id="5"]';
        $processed = $processor->process($content);

        self::assertStringContainsString('vl-product-embed', $processed);
        self::assertStringContainsString('Widget', $processed);
        self::assertStringContainsString('19.99', $processed);
        self::assertStringContainsString('A fine widget', $processed);
        self::assertTrue($processor->hadShortcodes());
    }

    public function testProductEmbedNotFoundRendersGenericCard(): void
    {
        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findById')->willReturn(null);

        $processor = $this->createProcessor(products: $products);

        // Legacy snapshot attrs are ignored — id-only storage
        $content   = '[product id="99" name="Missing" price="5.00" slug="missing"]';
        $processed = $processor->process($content);

        self::assertStringContainsString('vl-product-embed', $processed);
        self::assertStringContainsString('Product is no longer available', $processed);
        self::assertStringNotContainsString('Missing', $processed);
    }

    public function testProductEmbedDraftShowsUnavailableBanner(): void
    {
        $product = $this->makeProduct();
        $product->setId(1);
        $product->setStatus('draft');

        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findById')->willReturn($product);

        $processor = $this->createProcessor(products: $products);

        $processed = $processor->process('[product id="1"]');

        self::assertStringContainsString('Widget', $processed);
        self::assertStringContainsString('19.99', $processed);
        self::assertStringContainsString('Product is no longer available', $processed);
    }

    public function testProductEmbedExpiredShowsUnavailableBanner(): void
    {
        $product = $this->makeProduct();
        $product->setId(1);
        $product->setExpiresAt(new \DateTimeImmutable('-1 day'));

        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findById')->willReturn($product);

        $processor = $this->createProcessor(products: $products);

        $processed = $processor->process('[product id="1"]');

        self::assertStringContainsString('Widget', $processed);
        self::assertStringContainsString('Product is no longer available', $processed);
    }

    public function testProductEmbedUnlistedHasNoBanner(): void
    {
        $product = $this->makeProduct();
        $product->setId(1);
        $product->setStatus('unlisted');

        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findById')->willReturn($product);

        $processor = $this->createProcessor(products: $products);

        $processed = $processor->process('[product id="1"]');

        self::assertStringContainsString('Widget', $processed);
        self::assertStringNotContainsString('Product is no longer available', $processed);
    }

    public function testProductEmbedSaleActiveShowsSalePrice(): void
    {
        $product = $this->makeProduct(1, 'Widget', 1.29);
        $product->setId(1);
        $product->setSalePrice(0.79);

        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findById')->willReturn($product);

        $processor = $this->createProcessor(products: $products);

        $processed = $processor->process('[product id="1"]');

        self::assertStringContainsString('0.79', $processed);
        self::assertStringContainsString('line-through', $processed);
        self::assertStringContainsString('1.29', $processed);
        self::assertStringNotContainsString('Product is no longer available', $processed);
    }

    public function testProductEmbedScheduledSaleShowsRegularPrice(): void
    {
        $product = $this->makeProduct(1, 'Widget', 1.29);
        $product->setId(1);
        $product->setSalePrice(0.79);
        $product->setSalePriceStart(new \DateTimeImmutable('+1 day'));

        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findById')->willReturn($product);

        $processor = $this->createProcessor(products: $products);

        $processed = $processor->process('[product id="1"]');

        self::assertStringContainsString('1.29', $processed);
        self::assertStringNotContainsString('line-through', $processed);
    }

    public function testProductEmbedVariationRange(): void
    {
        $product = $this->makeProduct();
        $product->setId(1);

        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findById')->willReturn($product);
        $products->method('findVariationsByProductId')->willReturn([
            ['id' => 11, 'price' => 10.0, 'sale_price' => null, 'attributes' => ['Size' => 'S']],
            ['id' => 12, 'price' => 20.0, 'sale_price' => null, 'attributes' => ['Size' => 'M']],
        ]);

        $processor = $this->createProcessor(products: $products);

        $processed = $processor->process('[product id="1"]');

        self::assertStringContainsString('10.00', $processed);
        self::assertStringContainsString('20.00', $processed);
    }

    public function testProductEmbedVariationRangeWithFloatPrecisionDifference(): void
    {
        $product = $this->makeProduct();
        $product->setId(1);

        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findById')->willReturn($product);
        // Variations with identical formatted prices but slightly different raw floats
        $products->method('findVariationsByProductId')->willReturn([
            [
                'id'         => 11,
                'price'      => 10.000000000001,
                'sale_price' => null,
                'attributes' => ['Size' => 'S'],
            ],
            [
                'id'         => 12,
                'price'      => 10.00,
                'sale_price' => null,
                'attributes' => ['Size' => 'M'],
            ],
        ]);

        $processor = $this->createProcessor(products: $products);

        $processed = $processor->process('[product id="1"]');

        self::assertStringContainsString('10.00 USD', $processed);
        self::assertStringNotContainsString('10.00 – 10.00', $processed);
    }

    public function testProductEmbedSingleVariation(): void
    {
        $product = $this->makeProduct(1, 'Shirt', 25.0);
        $product->setId(1);

        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findById')->willReturn($product);
        $products->method('findVariationsByProductId')->willReturn([
            [
                'id'           => 11,
                'price'        => 30.0,
                'sale_price'   => 24.0,
                'attributes'   => ['Color' => 'Red', 'Size' => 'M'],
                'stock_status' => 'in_stock',
            ],
        ]);

        $processor = $this->createProcessor(products: $products);

        $processed = $processor->process('[product id="1" variation_id="11"]');

        self::assertStringContainsString('Red / M', $processed);
        self::assertStringContainsString('24.00', $processed);
        self::assertStringContainsString('line-through', $processed);
    }

    public function testProductEmbedUnknownVariationFallsBackToProduct(): void
    {
        $product = $this->makeProduct();
        $product->setId(1);

        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findById')->willReturn($product);
        $products->method('findVariationsByProductId')->willReturn([]);

        $processor = $this->createProcessor(products: $products);

        $processed = $processor->process('[product id="1" variation_id="99"]');

        self::assertStringContainsString('Widget', $processed);
        self::assertStringContainsString('19.99', $processed);
    }

    public function testProductEmbedMissingId(): void
    {
        $content   = '[product name="No ID"]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('[product name="No ID"]', $processed);
    }

    public function testProductEmbedZeroId(): void
    {
        $content   = '[product id="0"]';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('[product id="0"]', $processed);
    }

    public function testProductEmbedHiddenImage(): void
    {
        $product = $this->makeProduct();
        $product->setId(1);

        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findById')->willReturn($product);

        $processor = $this->createProcessor(products: $products);

        $content   = '[product id="1" show_image="false"]';
        $processed = $processor->process($content);

        self::assertStringContainsString('vl-product-embed', $processed);
        self::assertStringNotContainsString('object-fit:cover', $processed);
    }

    public function testProductEmbedHiddenPrice(): void
    {
        $product = $this->makeProduct();
        $product->setId(1);

        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findById')->willReturn($product);

        $processor = $this->createProcessor(products: $products);

        $content   = '[product id="1" show_price="false"]';
        $processed = $processor->process($content);

        self::assertStringNotContainsString('19.99', $processed);
    }

    public function testProductEmbedHiddenDescription(): void
    {
        $product = $this->makeProduct();
        $product->setId(1);

        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findById')->willReturn($product);

        $processor = $this->createProcessor(products: $products);

        $content   = '[product id="1" show_description="false"]';
        $processed = $processor->process($content);

        self::assertStringNotContainsString('A fine widget', $processed);
    }

    public function testProductEmbedWithImage(): void
    {
        $product = $this->makeProduct();
        $product->setId(1);
        $product->setFeaturedImageId(10);

        $media = new Media(1, 'prod.jpg', 'prod.jpg', 'image/jpeg', 1000, '2026/prod.jpg');

        $mediaRepo = $this->createStub(MediaRepositoryInterface::class);
        $mediaRepo->method('findById')->willReturn($media);

        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findById')->willReturn($product);

        $processor = $this->createProcessor(products: $products, media: $mediaRepo);

        $content   = '[product id="1"]';
        $processed = $processor->process($content);

        self::assertStringContainsString('prod.jpg', $processed);
    }

    public function testProductEmbedWithBgColor(): void
    {
        $product = $this->makeProduct();
        $product->setId(1);

        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findById')->willReturn($product);

        $processor = $this->createProcessor(products: $products);

        $content   = '[product id="1" bg="#eef"]';
        $processed = $processor->process($content);

        self::assertStringContainsString('background:#eef', $processed);
    }

    public function testProductEmbedTransparentBg(): void
    {
        $product = $this->makeProduct();
        $product->setId(1);

        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findById')->willReturn($product);

        $processor = $this->createProcessor(products: $products);

        $content   = '[product id="1" bg="transparent"]';
        $processed = $processor->process($content);

        self::assertStringContainsString('background:transparent', $processed);
    }

    public function testProductEmbedThemeDark(): void
    {
        $product = $this->makeProduct();
        $product->setId(1);

        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findById')->willReturn($product);

        $settings = $this->createSettings(['active_theme' => 'dark']);

        $processor = $this->createProcessor(products: $products, settings: $settings);

        $content   = '[product id="1"]';
        $processed = $processor->process($content);

        self::assertStringContainsString('background:#1f2937', $processed);
    }

    public function testProductEmbedThemeVivid(): void
    {
        $product = $this->makeProduct();
        $product->setId(1);

        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findById')->willReturn($product);

        $settings = $this->createSettings(['active_theme' => 'vivid']);

        $processor = $this->createProcessor(products: $products, settings: $settings);

        $content   = '[product id="1"]';
        $processed = $processor->process($content);

        self::assertStringContainsString('background:#f3f4f6', $processed);
    }

    public function testProductEmbedWithTranslation(): void
    {
        $product = $this->makeProduct();
        $product->setId(1);
        $product->setTranslationOf(1);

        $trProduct = new Product('Vijge', new Slug('widget-tr'));
        $trProduct->setId(2);
        $trProduct->setPrice(29.99);
        $trProduct->setLanguage('tr');
        $trProduct->setStatus('published');

        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findById')->willReturn($product);
        $products->method('findTranslations')->willReturn([$trProduct]);

        $processor = $this->createProcessor(products: $products);

        $post = new Post('TR', new Slug('tr'), 1);
        $post->setLanguage('tr');

        $content   = '[product id="1"]';
        $processed = $processor->process($content, $post);

        self::assertStringContainsString('Vijge', $processed);
        self::assertStringContainsString('29.99', $processed);
    }

    public function testProductEmbedInlineAttrsAreIgnored(): void
    {
        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findById')->willReturn(null);

        $processor = $this->createProcessor(products: $products);

        // Legacy snapshot attrs are ignored — id-only storage renders generic card
        $content   = '[product id="1" name="Inline" price="9.99" slug="inline" description="Desc"]';
        $processed = $processor->process($content);

        self::assertStringContainsString('vl-product-embed', $processed);
        self::assertStringContainsString('Product is no longer available', $processed);
        self::assertStringNotContainsString('Inline', $processed);
        self::assertStringNotContainsString('9.99', $processed);
    }

    public function testProductEmbedWrappedInPTag(): void
    {
        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findById')->willReturn(null);

        $processor = $this->createProcessor(products: $products);

        $content   = '<p>[product id="1" name="X" slug="x"]</p>';
        $processed = $processor->process($content);

        self::assertStringContainsString('vl-product-embed', $processed);
    }

    // ── Lightbox Overlay ────────────────────────────────────

    public function testLightboxOverlayRenderedFromInlineImage(): void
    {
        $content   = '<p><img src="/uploads/2026/test.jpg" data-lightbox="true" alt=""></p>';
        $processed = $this->processor->process($content);

        self::assertStringContainsString('lbActive', $processed);
        self::assertStringContainsString('@lightbox-open.window', $processed);
    }

    public function testLightboxOverlayRenderedOnceMultipleImages(): void
    {
        $content   = '<img src="/uploads/a.jpg" data-lightbox="true"> <img src="/uploads/b.jpg" data-lightbox="true">';
        $processed = $this->processor->process($content);

        $count = substr_count($processed, '@lightbox-open.window');
        self::assertSame(1, $count);
    }

    // ── Image Alt Localization ──────────────────────────────

    public function testImgAltLocalizationNoMediaFound(): void
    {
        $mediaRepo = $this->createStub(MediaRepositoryInterface::class);
        $mediaRepo->method('findByPath')->willReturn(null);

        $processor = $this->createProcessor(media: $mediaRepo);

        $content   = '<img src="/uploads/2026/unknown.jpg" alt="original">';
        $processed = $processor->process($content);

        self::assertStringContainsString('alt="original"', $processed);
    }

    public function testImgAltLocalizationNonUploadUrl(): void
    {
        $processor = $this->createProcessor();

        $content   = '<img src="https://example.com/image.jpg" alt="external">';
        $processed = $processor->process($content);

        self::assertStringContainsString('alt="external"', $processed);
    }

    public function testImgAltLocalizationWithNoExistingAlt(): void
    {
        $media = new Media(1, 'pic.jpg', 'pic.jpg', 'image/jpeg', 100, '2026/pic.jpg');
        $media->setAltText('localized alt');

        $mediaRepo = $this->createStub(MediaRepositoryInterface::class);
        $mediaRepo->method('findByPath')->willReturn($media);

        $processor = $this->createProcessor(media: $mediaRepo);

        $content   = '<img src="/uploads/2026/pic.jpg">';
        $processed = $processor->process($content);

        self::assertStringContainsString('alt="localized alt"', $processed);
    }

    public function testImgAltLocalizationWithBasePath(): void
    {
        $media = new Media(1, 'pic.jpg', 'pic.jpg', 'image/jpeg', 100, '2026/pic.jpg');
        $media->setAltText('localized');

        $mediaRepo = $this->createStub(MediaRepositoryInterface::class);
        $mediaRepo->method('findByPath')->willReturn($media);

        $processor = $this->createProcessor(media: $mediaRepo, basePath: '/lp');

        $content   = '<img src="/lp/uploads/2026/pic.jpg">';
        $processed = $processor->process($content);

        self::assertStringContainsString('alt="localized"', $processed);
    }

    public function testImgAltLocalizationThumbnailPathDecoded(): void
    {
        $media = new Media(1, 'pic.jpg', 'pic.jpg', 'image/jpeg', 100, '2026/pic.jpg');
        $media->setAltText('thumb alt');

        $mediaRepo = $this->createStub(MediaRepositoryInterface::class);
        $mediaRepo->method('findByPath')->willReturn($media);

        $processor = $this->createProcessor(media: $mediaRepo);

        $content   = '<img src="/uploads/2026/pic-medium.jpg">';
        $processed = $processor->process($content);

        self::assertStringContainsString('alt="thumb alt"', $processed);
    }

    public function testImgAltLocalizationEmptyAltNotSet(): void
    {
        $media = new Media(1, 'pic.jpg', 'pic.jpg', 'image/jpeg', 100, '2026/pic.jpg');
        $media->setAltText('  ');

        $mediaRepo = $this->createStub(MediaRepositoryInterface::class);
        $mediaRepo->method('findByPath')->willReturn($media);

        $processor = $this->createProcessor(media: $mediaRepo);

        $content   = '<img src="/uploads/2026/pic.jpg" alt="existing">';
        $processed = $processor->process($content);

        self::assertStringContainsString('alt="existing"', $processed);
    }

    // ── Strip for Feed ──────────────────────────────────────

    public function testStripForFeedRemovesAllShortcodeTypes(): void
    {
        $content  = '[form slug="test"] [newsletter_form] [toc] [pages] [field name="x"] [ad] [carousel images="a.jpg"] [product id="1"] [link_button href="/x"]CTA[/link_button] <!--spacer size=10 unit=px-->';
        $stripped = $this->processor->stripForFeed($content);

        self::assertStringNotContainsString('[form', $stripped);
        self::assertStringNotContainsString('[newsletter_form', $stripped);
        self::assertStringNotContainsString('[toc', $stripped);
        self::assertStringNotContainsString('[pages', $stripped);
        self::assertStringNotContainsString('[field', $stripped);
        self::assertStringNotContainsString('[ad]', $stripped);
        self::assertStringNotContainsString('[carousel', $stripped);
        self::assertStringNotContainsString('[product', $stripped);
        self::assertStringNotContainsString('[link_button', $stripped);
        self::assertStringNotContainsString('<!--spacer', $stripped);
    }

    public function testStripForFeedRemovesFormHtml(): void
    {
        $content  = '<div data-form-slug="contact">form HTML</div>';
        $stripped = $this->processor->stripForFeed($content);

        self::assertStringNotContainsString('data-form-slug', $stripped);
    }

    public function testStripForFeedRemovesLinkButtonWithClosingTag(): void
    {
        $content  = '[link_button href="/go" target="_blank"]Click Here[/link_button]';
        $stripped = $this->processor->stripForFeed($content);

        self::assertStringNotContainsString('link_button', $stripped);
        self::assertStringNotContainsString('Click Here', $stripped);
    }

    public function testStripForFeedRemovesSpacerBlock(): void
    {
        $content  = '<div class="vl-spacer-block" style="height:40px"></div>';
        $stripped = $this->processor->stripForFeed($content);

        self::assertStringNotContainsString('vl-spacer-block', $stripped);
    }

    public function testStripForFeedCleansEmptyPTags(): void
    {
        $content  = '<p>Content</p>  <p>  </p> <p>More</p>';
        $stripped = $this->processor->stripForFeed($content);

        self::assertStringContainsString('Content', $stripped);
        self::assertStringContainsString('More', $stripped);
        self::assertStringNotContainsString('<p>  </p>', $stripped);
    }

    public function testStripForFeedEmptyContent(): void
    {
        self::assertSame('', $this->processor->stripForFeed(''));
    }

    // ── Combined Shortcodes ─────────────────────────────────

    public function testMultipleShortcodesInSameContent(): void
    {
        $settings = $this->createSettings([
            'advertisement_enabled' => '1',
            'advertisement_code'    => '<div>AD</div>',
        ]);

        $processor = $this->createProcessor(settings: $settings);

        $content   = '<h2>Title</h2>[toc]<p>[ad]</p><!--spacer size=20 unit=em-->';
        $processed = $processor->process($content);

        self::assertStringContainsString('vl-toc', $processed);
        self::assertStringContainsString('<div>AD</div>', $processed);
        self::assertStringContainsString('height:20em', $processed);
    }

    public function testHadShortcodesTrueWhenFormPresent(): void
    {
        $forms = $this->createStub(FormRepositoryInterface::class);
        $forms->method('findBySlug')->willReturn($this->makeForm());

        $processor = $this->createProcessor(forms: $forms);
        $processor->process('[form slug="contact"]');

        self::assertTrue($processor->hadShortcodes());
    }

    public function testHadShortcodesTrueWhenProductPresent(): void
    {
        $product = $this->makeProduct();
        $product->setId(1);

        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findById')->willReturn($product);

        $processor = $this->createProcessor(products: $products);
        $processor->process('[product id="1"]');

        self::assertTrue($processor->hadShortcodes());
    }

    public function testHadShortcodesTrueWhenAdEnabled(): void
    {
        $settings = $this->createSettings([
            'advertisement_enabled' => '1',
            'advertisement_code'    => '<div>AD</div>',
        ]);

        $processor = $this->createProcessor(settings: $settings);
        $processor->process('<p>[ad]</p>');

        self::assertTrue($processor->hadShortcodes());
    }

    public function testHadShortcodesTrueWhenNewsletterFormPresent(): void
    {
        $processor = $this->createProcessor();
        $processor->process('[newsletter_form]');

        self::assertTrue($processor->hadShortcodes());
    }

    public function testHadShortcodesTrueWhenLinkButtonPresent(): void
    {
        $processor = $this->createProcessor();
        $processor->process('[link_button href="/go"]Go[/link_button]');

        self::assertTrue($processor->hadShortcodes());
    }

    // ── Redirect ──────────────────────────────────────────────

    public function testRedirectRendersBoxWithCountdown(): void
    {
        $processed = $this->processor->process('[redirect url="https://example.com" seconds="5"]');

        self::assertStringContainsString('vl-redirect-box', $processed);
        self::assertStringContainsString('data-vl-redirect-count', $processed);
        self::assertStringContainsString('href="https://example.com"', $processed);
        self::assertStringContainsString('window.location.replace', $processed);
        self::assertStringNotContainsString('[redirect', $processed);
        self::assertTrue($this->processor->hadShortcodes());
    }

    public function testRedirectParagraphWrapped(): void
    {
        $processed = $this->processor->process('<p>[redirect url="/pricing" seconds="3"]</p>');

        self::assertStringContainsString('vl-redirect-box', $processed);
        self::assertStringContainsString('href="/pricing"', $processed);
        self::assertStringNotContainsString('[redirect', $processed);
    }

    public function testRedirectBareSlugBecomesSitePath(): void
    {
        $processed = $this->processor->process('[redirect url="pricing"]');

        self::assertStringContainsString('href="/pricing"', $processed);
    }

    public function testRedirectEmptyUrlRendersNothing(): void
    {
        self::assertSame('', $this->processor->process('[redirect url="" seconds="5"]'));
        self::assertSame('', $this->processor->process('[redirect]'));
    }

    public function testRedirectDangerousSchemeRendersNothing(): void
    {
        $processed = $this->processor->process('[redirect url="javascript:alert(1)"]');

        self::assertStringNotContainsString('vl-redirect-box', $processed);
        self::assertStringNotContainsString('javascript:', $processed);
    }

    public function testRedirectSecondsDefaultAndClamp(): void
    {
        $default = $this->processor->process('[redirect url="/a"]');
        self::assertStringContainsString('>5<', $default);

        $clamped = $this->processor->process('[redirect url="/a" seconds="999"]');
        self::assertStringContainsString('>300<', $clamped);
    }

    public function testRedirectInstantThrowsRedirect302Exception(): void
    {
        try {
            $this->processor->process('[redirect url="/a" seconds="0"]');
            self::fail('Expected Redirect302Exception was not thrown');
        } catch (\ValeApp\Domain\Shared\Exception\Redirect302Exception $e) {
            self::assertStringEndsWith('/a', $e->getUrl());
        }
    }

    public function testRedirectOnlyFirstDrivesNavigation(): void
    {
        $processed = $this->processor->process(
            '[redirect url="/first" seconds="5"] text [redirect url="/second" seconds="5"]'
        );

        self::assertSame(2, substr_count($processed, 'vl-redirect-box'));
        self::assertSame(1, substr_count($processed, 'window.location.replace'));
        self::assertStringContainsString('href="/second"', $processed);
    }

    public function testRedirectCountdownUpdatesEveryBox(): void
    {
        $processed = $this->processor->process(
            '[redirect url="/first" seconds="5"] text [redirect url="/second" seconds="7"]'
        );

        // Both boxes show their own remaining seconds (2 spans), and the single
        // countdown interval updates all of them (querySelectorAll, +1
        // occurrence inside the script), not just the first.
        self::assertSame(3, substr_count($processed, 'data-vl-redirect-count'));
        self::assertStringContainsString('querySelectorAll("[data-vl-redirect-count]")', $processed);
        self::assertStringContainsString('e.forEach(function(n){n.textContent=l;})', $processed);
        self::assertStringContainsString('<strong data-vl-redirect-count>5</strong>', $processed);
        self::assertStringContainsString('<strong data-vl-redirect-count>7</strong>', $processed);
    }

    public function testProcessInternalLinksResolvesPageUrlDynamically(): void
    {
        $page = new \ValeApp\Domain\Content\Entity\Post('New Page Title', new \ValeApp\Domain\Shared\ValueObject\Slug('new-page-slug'), 1);
        $page->setContentType('page');
        $page->setStatus('published');

        $posts = $this->createStub(PostRepositoryInterface::class);
        $posts->method('findById')->willReturnCallback(fn ($id) => $id === 10 ? $page : null);
        $posts->method('buildPagePath')->willReturn('new-page-slug');

        $processor = $this->createProcessor(posts: $posts);

        $html      = '<p>Check <a href="/old-slug" data-vl-type="page" data-vl-id="10">My Page</a> here.</p>';
        $processed = $processor->process($html);

        self::assertStringContainsString('href="/new-page-slug"', $processed);
        self::assertStringContainsString('>My Page</a>', $processed);
    }

    public function testProcessInternalLinksStripsAnchorWhenEntityDeleted(): void
    {
        $posts = $this->createStub(PostRepositoryInterface::class);
        $posts->method('findById')->willReturn(null);

        $processor = $this->createProcessor(posts: $posts);

        $html      = '<p>Check <a href="/old-slug" data-vl-type="page" data-vl-id="999">Deleted Page</a> here.</p>';
        $processed = $processor->process($html);

        self::assertStringNotContainsString('<a', $processed);
        self::assertStringContainsString('Check Deleted Page here.', $processed);
    }

    public function testRedirectInternalLinkDynamic302(): void
    {
        $page = new \ValeApp\Domain\Content\Entity\Post('Target Page', new \ValeApp\Domain\Shared\ValueObject\Slug('target-slug'), 1);
        $page->setContentType('page');
        $page->setStatus('published');

        $posts = $this->createStub(PostRepositoryInterface::class);
        $posts->method('findById')->willReturnCallback(fn ($id) => $id === 15 ? $page : null);
        $posts->method('buildPagePath')->willReturn('target-slug');

        $processor = $this->createProcessor(posts: $posts);

        try {
            $processor->process('[redirect internal_type="page" internal_id="15" seconds="0"]');
            self::fail('Expected Redirect302Exception was not thrown');
        } catch (\ValeApp\Domain\Shared\Exception\Redirect302Exception $e) {
            self::assertStringEndsWith('/target-slug', $e->getUrl());
        }
    }
}
