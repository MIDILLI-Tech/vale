<?php

declare(strict_types=1);

namespace ValeApp\Tests\Integration\Admin;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Views\Twig;
use ValeApp\App\Controllers\Admin\FormController;
use ValeApp\Application\Form\FormCaptchaService;
use ValeApp\Domain\Form\Entity\Form;
use ValeApp\Domain\Form\Repository\FormRepositoryInterface;
use ValeApp\Domain\Membership\Repository\PlanRepositoryInterface;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Infrastructure\Content\SlugService;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

final class FormControllerTest extends TestCase
{
    private $translator;
    private $twig;
    private $forms;
    private $slugService;
    private $session;
    private $settings;
    private $plans;
    private $formCaptcha;
    private $subscribers;
    private $controller;

    protected function setUp(): void
    {
        $this->translator  = $this->createStub(Translator::class);
        $this->twig        = $this->createStub(Twig::class);
        $this->forms       = $this->createStub(FormRepositoryInterface::class);
        $this->slugService = $this->createStub(SlugService::class);
        $this->session     = $this->createStub(SessionManager::class);
        $this->settings    = $this->createStub(\ValeApp\Infrastructure\Persistence\DbalSettingsRepository::class);
        $this->plans       = $this->createStub(PlanRepositoryInterface::class);
        $this->subscribers = $this->createStub(SubscriberRepositoryInterface::class);
        $this->formCaptcha = new FormCaptchaService(
            $this->forms,
            $this->settings,
        );

        $this->controller = $this->buildController();
    }

    private function buildController(): FormController
    {
        return new FormController(
            $this->translator,
            $this->twig,
            $this->forms,
            $this->slugService,
            $this->session,
            $this->settings,
            $this->plans,
            $this->formCaptcha,
            $this->subscribers
        );
    }

    public function testIndexRendersTemplate(): void
    {
        $this->twig       = $this->createMock(Twig::class);
        $this->controller = $this->buildController();

        $this->forms->method('findAll')->willReturn([]);
        $this->twig->expects(self::once())->method('render')->with(
            self::anything(),
            'admin/forms/index.twig',
            self::callback(fn ($data) => is_array($data['forms']))
        );

        $request = (new RequestFactory())->createRequest('GET', '/admin/forms');
        $this->controller->index($request, (new ResponseFactory())->createResponse());
    }

    public function testStoreCreatesFormAndRedirects(): void
    {
        $this->forms      = $this->createMock(FormRepositoryInterface::class);
        $this->session    = $this->createMock(SessionManager::class);
        $this->controller = $this->buildController();

        $data    = ['name' => 'Contact Us'];
        $request = (new RequestFactory())->createRequest('POST', '/admin/forms')
            ->withParsedBody($data);

        $this->slugService->method('generate')->willReturn(new Slug('contact-us'));
        $this->forms->expects(self::once())->method('save');
        $this->session->expects(self::once())->method('flash')->with('success', self::anything());

        $response = $this->controller->store($request, (new ResponseFactory())->createResponse());

        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('/admin/forms', $response->getHeaderLine('Location'));
    }

    public function testStoreBlockedWhenCaptchaProviderHasNoKeys(): void
    {
        $this->forms      = $this->createMock(FormRepositoryInterface::class);
        $this->session    = $this->createMock(SessionManager::class);
        $this->controller = $this->buildController();

        $this->slugService->method('generate')->willReturn(new Slug('turnstile-form'));
        $this->forms->expects(self::never())->method('save');
        $this->session->expects(self::once())->method('flash')
            ->with('error', self::stringContains('not configured in Privacy settings'));

        $request = (new RequestFactory())->createRequest('POST', '/admin/forms')
            ->withParsedBody(['name' => 'Turnstile Form', 'captcha_type' => 'turnstile']);

        $response = $this->controller->store($request, (new ResponseFactory())->createResponse());

        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('/admin/forms/create', $response->getHeaderLine('Location'));
    }

    public function testStoreAllowedWhenCaptchaProviderKeysExist(): void
    {
        $this->forms      = $this->createMock(FormRepositoryInterface::class);
        $this->session    = $this->createMock(SessionManager::class);
        $this->controller = $this->buildController();

        $settings = $this->createStub(\ValeApp\Infrastructure\Persistence\DbalSettingsRepository::class);
        $settings->method('get')->willReturnCallback(
            fn (string $key, mixed $default = null) => match ($key) {
                'turnstile_site_key', 'turnstile_secret_key' => 'configured',
                default                                      => $default,
            }
        );
        $this->settings    = $settings;
        $this->formCaptcha = new FormCaptchaService($this->forms, $settings);
        $this->controller  = $this->buildController();

        $this->forms->expects(self::once())->method('save');
        $this->session->expects(self::once())->method('flash')->with('success', self::anything());

        $this->slugService->method('generate')->willReturn(new Slug('turnstile-form'));

        $request = (new RequestFactory())->createRequest('POST', '/admin/forms')
            ->withParsedBody(['name' => 'Turnstile Form', 'captcha_type' => 'turnstile']);

        $response = $this->controller->store($request, (new ResponseFactory())->createResponse());

        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('/admin/forms', $response->getHeaderLine('Location'));
    }

    public function testDestroyForm(): void
    {
        $this->forms      = $this->createMock(FormRepositoryInterface::class);
        $this->session    = $this->createMock(SessionManager::class);
        $this->controller = $this->buildController();

        $this->forms->expects(self::once())->method('delete')->with(123);
        $this->session->expects(self::once())->method('flash')->with('success', self::anything());

        $request  = (new RequestFactory())->createRequest('DELETE', '/admin/forms/123');
        $response = $this->controller->destroy($request, (new ResponseFactory())->createResponse(), ['id' => '123']);

        self::assertSame(302, $response->getStatusCode());
    }

    public function testCreatePassesSubscriberTagsToView(): void
    {
        $this->twig        = $this->createMock(Twig::class);
        $this->subscribers = $this->createStub(SubscriberRepositoryInterface::class);
        $this->subscribers->method('getAllTags')->willReturn(['news', 'vip']);
        $this->controller = $this->buildController();

        $this->twig->expects(self::once())->method('render')->with(
            self::anything(),
            'admin/forms/form.twig',
            self::callback(fn ($data) => ($data['subscriber_tags'] ?? null) === ['news', 'vip'])
        );

        $request = (new RequestFactory())->createRequest('GET', '/admin/forms/create');
        $this->controller->create($request, (new ResponseFactory())->createResponse());
    }

    public function testStoreNormalizesNewsletterSubscribeTags(): void
    {
        $this->forms      = $this->createMock(FormRepositoryInterface::class);
        $this->session    = $this->createMock(SessionManager::class);
        $this->controller = $this->buildController();

        $this->slugService->method('generate')->willReturn(new Slug('nl-form'));
        $this->session->expects(self::once())->method('flash')->with('success', self::anything());

        $saved = null;
        $this->forms->expects(self::once())->method('save')
            ->willReturnCallback(static function (Form $form) use (&$saved): Form {
                $saved = $form;
                return $form;
            });

        $fields = [
            ['type' => 'email', 'label' => 'Email', 'name' => 'email', 'required' => true, 'width' => '100'],
            ['type' => 'text', 'label' => 'Name', 'name' => 'name', 'required' => false, 'width' => '100', 'tags' => ['stale']],
            ['type' => 'newsletter_subscribe', 'label' => 'Subscribe', 'name' => 'sub', 'required' => false, 'width' => '100', 'tags' => [' vip ', '', 'vip', 'news']],
        ];
        $request = (new RequestFactory())->createRequest('POST', '/admin/forms')
            ->withParsedBody(['name' => 'NL Form', 'fields_json' => json_encode($fields)]);

        $response = $this->controller->store($request, (new ResponseFactory())->createResponse());

        self::assertSame(302, $response->getStatusCode());
        self::assertNotNull($saved);
        $savedFields = $saved->getFields();
        self::assertSame(['vip', 'news'], $savedFields[2]['tags']);
        self::assertArrayNotHasKey('tags', $savedFields[0]);
        self::assertArrayNotHasKey('tags', $savedFields[1]);
    }

    public function testStoreNormalizesPlaceholderAndOptionTitles(): void
    {
        $this->forms      = $this->createMock(FormRepositoryInterface::class);
        $this->session    = $this->createMock(SessionManager::class);
        $this->controller = $this->buildController();

        $this->slugService->method('generate')->willReturn(new Slug('normalized'));
        $this->session->expects(self::once())->method('flash')->with('success', self::anything());

        $saved = null;
        $this->forms->expects(self::once())->method('save')
            ->willReturnCallback(static function (Form $form) use (&$saved): Form {
                $saved = $form;
                return $form;
            });

        $fields = [
            ['type' => 'text', 'label' => 'Name', 'name' => 'name', 'placeholder' => '  Your name  ', 'width' => '100'],
            ['type' => 'textarea', 'label' => 'Message', 'name' => 'message', 'placeholder' => '   ', 'width' => '100'],
            ['type' => 'select', 'label' => 'Topic', 'name' => 'topic', 'options' => [
                ['value' => 'General', 'title' => ' General inquiries '],
                ['value' => 'Support', 'title' => ''],
                ['value' => ''],
            ], '_optionsText' => 'ignored', '_titlesText' => 'ignored', 'width' => '100'],
            ['type' => 'checkbox', 'label' => 'Extras', 'name' => 'extras', 'options' => ['Gift wrap'], 'width' => '100'],
        ];
        $request = (new RequestFactory())->createRequest('POST', '/admin/forms')
            ->withParsedBody(['name' => 'Normalized', 'fields_json' => json_encode($fields)]);

        $response = $this->controller->store($request, (new ResponseFactory())->createResponse());

        self::assertSame(302, $response->getStatusCode());
        self::assertNotNull($saved);
        $savedFields = $saved->getFields();

        self::assertSame('Your name', $savedFields[0]['placeholder']);
        self::assertArrayNotHasKey('placeholder', $savedFields[1]);
        self::assertSame([
            ['value' => 'General', 'title' => 'General inquiries'],
            ['value' => 'Support', 'title' => ''],
        ], $savedFields[2]['options']);
        self::assertArrayNotHasKey('_optionsText', $savedFields[2]);
        self::assertArrayNotHasKey('_titlesText', $savedFields[2]);
        self::assertSame([['value' => 'Gift wrap', 'title' => '']], $savedFields[3]['options']);
    }
}
