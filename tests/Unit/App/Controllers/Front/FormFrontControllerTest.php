<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Controllers\Front;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\Twig;
use ValeApp\App\Controllers\Front\FormFrontController;
use ValeApp\Application\Form\FormAccessService;
use ValeApp\Application\Form\FormCaptchaService;
use ValeApp\Application\Mail\MailService;
use ValeApp\Application\Newsletter\AutomationService;
use ValeApp\Application\Webhook\OutboundWebhookService;
use ValeApp\Domain\Form\Entity\Form;
use ValeApp\Domain\Form\Entity\FormSubmission;
use ValeApp\Domain\Form\Repository\FormRepositoryInterface;
use ValeApp\Domain\Mail\Port\MailInterface;
use ValeApp\Domain\Membership\Repository\PlanRepositoryInterface;
use ValeApp\Domain\Newsletter\Entity\AutomationSequence;
use ValeApp\Domain\Newsletter\Entity\AutomationStep;
use ValeApp\Domain\Newsletter\Entity\Subscriber;
use ValeApp\Domain\Newsletter\Repository\AutomationRepositoryInterface;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Domain\Shared\Entity\Webhook;
use ValeApp\Domain\Shared\Event\EventDispatcherInterface;
use ValeApp\Domain\Shared\Repository\WebhookRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Repository\OrderRepositoryInterface;
use ValeApp\Domain\User\Repository\UserRepositoryInterface;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Security\IpAnonymizer;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Theme\ThemeManager;
use ValeApp\Infrastructure\Translation\Translator;

final class FormFrontControllerTest extends TestCase
{
    private $twig;
    private $forms;
    private $mail;
    private $session;
    private $subscribers;
    private $settings;
    private $db;
    private $ipAnonymizer;
    private $webhooks;
    private $webhookRepo;
    private $events;
    private $translator;
    private $users;
    private $plans;
    private $formAccess;
    private $formCaptcha;
    private $automationService;
    private $automationRepo;
    private $controller;

    protected function setUp(): void
    {
        $this->twig           = $this->createStub(Twig::class);
        $this->forms          = $this->createStub(FormRepositoryInterface::class);
        $this->session        = $this->createStub(SessionManager::class);
        $this->subscribers    = $this->createStub(SubscriberRepositoryInterface::class);
        $this->settings       = $this->createStub(DbalSettingsRepository::class);
        $this->db             = $this->createStub(Connection::class);
        $this->ipAnonymizer   = new IpAnonymizer();
        $this->users          = $this->createStub(UserRepositoryInterface::class);
        $this->plans          = $this->createStub(PlanRepositoryInterface::class);
        $this->automationRepo = $this->createStub(AutomationRepositoryInterface::class);
        // AutomationService is final: build a real one with stubbed repos
        $this->automationService = new AutomationService(
            $this->automationRepo,
            $this->subscribers,
            $this->createStub(MailInterface::class),
            $this->createStub(OrderRepositoryInterface::class),
            'http://localhost',
        );
        $this->formAccess   = new FormAccessService(
            $this->session,
            $this->subscribers,
            $this->settings,
        );
        $this->formCaptcha = new FormCaptchaService(
            $this->forms,
            $this->settings,
        );

        // Build a real OutboundWebhookService with a stubbed WebhookRepositoryInterface since OutboundWebhookService is final
        $this->webhookRepo = $this->createStub(WebhookRepositoryInterface::class);
        $this->webhooks    = new OutboundWebhookService($this->webhookRepo);
        $this->events      = $this->createStub(EventDispatcherInterface::class);

        // Build a real MailService with mocked dependencies since MailService is final
        $mailer            = $this->createStub(MailInterface::class);
        $this->translator  = $this->createStub(Translator::class);
        $this->translator->method('get')->willReturn('New form submission');

        $this->mail        = new MailService(
            $mailer,
            $this->twig,
            $this->settings,
            $this->translator,
            $this->createStub(ThemeManager::class)
        );

        $this->controller = $this->buildController();
    }

    private function buildController(): FormFrontController
    {
        return new FormFrontController(
            $this->twig,
            $this->forms,
            $this->mail,
            $this->session,
            $this->subscribers,
            $this->settings,
            $this->db,
            $this->ipAnonymizer,
            $this->webhooks,
            $this->events,
            $this->translator,
            $this->users,
            $this->plans,
            $this->formAccess,
            $this->formCaptcha,
            $this->automationService
        );
    }

    public function testSubmitTriggersFormSubmittedWebhook(): void
    {
        $form = new Form('Contact Form', new Slug('contact'));
        $form->setId(42);
        $form->setEnabled(true);
        $form->setFields([
            ['name' => 'fullname', 'type' => 'text', 'label' => 'Full Name', 'required' => true],
            ['name' => 'email', 'type' => 'email', 'label' => 'Email Address', 'required' => true],
        ]);
        $form->setSettings(['captcha_type' => 'none']);

        $this->forms->method('findBySlug')->willReturn($form);

        $parsedBody = [
            'fields' => [
                'fullname' => 'Alice Smith',
                'email'    => 'alice@example.com',
            ],
        ];

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/forms/submit/contact', [
            'REMOTE_ADDR' => '192.168.1.1',
        ])->withParsedBody($parsedBody);

        $webhook = new Webhook('Test Webhook', 'http://localhost/webhook-endpoint');
        $webhook->setEvents(['form.submitted']);

        $findActiveCalls = 0;
        $webhookRepo     = $this->createStub(WebhookRepositoryInterface::class);
        $webhookRepo->method('findActive')->willReturnCallback(
            static function () use ($webhook, &$findActiveCalls): array {
                $findActiveCalls++;
                return [$webhook];
            }
        );

        $this->webhooks   = new OutboundWebhookService($webhookRepo);
        $this->controller = $this->buildController();

        $this->twig->method('render')->willReturn((new ResponseFactory())->createResponse());

        $response = $this->controller->submit($request, (new ResponseFactory())->createResponse(), ['slug' => 'contact']);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $findActiveCalls);
    }

    public function testSubmitWebFormFailsWhenOnlyRegisteredAndNotLoggedIn(): void
    {
        $form = new Form('Members Form', new Slug('members-form'));
        $form->setId(10);
        $form->setEnabled(true);
        $form->setSettings(['only_registered' => true]);

        $sessionKeys = [];
        $session     = $this->createStub(SessionManager::class);
        $session->method('get')->willReturnCallback(
            static function (string $key, mixed $default = null) use (&$sessionKeys) {
                $sessionKeys[] = $key;
                return null;
            }
        );

        $this->forms->method('findBySlug')->willReturn($form);

        $this->session    = $session;
        $this->formAccess = new FormAccessService(
            $this->session,
            $this->subscribers,
            $this->settings,
        );
        $this->controller = $this->buildController();

        $this->twig->method('render')->willReturn((new ResponseFactory())->createResponse()->withStatus(403));

        $request  = (new ServerRequestFactory())->createServerRequest('POST', '/form/members-form');
        $response = $this->controller->submit($request, (new ResponseFactory())->createResponse(), ['slug' => 'members-form']);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['user_id'], $sessionKeys);
    }

    public function testSubmitExternalFailsWhenOnlyRegisteredAndUserNotFound(): void
    {
        $form = new Form('External Members Form', new Slug('ext-members'));
        $form->setId(11);
        $form->setEnabled(true);
        $form->setSettings(['allow_external' => true, 'only_registered' => true]);

        $this->forms->method('findBySlug')->willReturn($form);
        $this->users->method('findByEmail')->willReturn(null);
        $this->subscribers->method('findByEmail')->willReturn(null);

        $parsedBody = [
            'fields' => ['email' => 'guest@example.com'],
        ];

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/form/ext-members')
            ->withParsedBody($parsedBody);

        $response = $this->controller->submitExternal($request, (new ResponseFactory())->createResponse(), ['slug' => 'ext-members']);

        self::assertSame(403, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('Submissions for this form are restricted to registered users.', $body);
    }

    public function testSubmitBlockedWhenDuplicateEmailAndMultipleSubmissionsDisabled(): void
    {
        $form = new Form('Contact Form', new Slug('contact'));
        $form->setId(42);
        $form->setEnabled(true);
        $form->setFields([
            ['name' => 'fullname', 'type' => 'text', 'label' => 'Full Name', 'required' => true],
            ['name' => 'email', 'type' => 'email', 'label' => 'Email Address', 'required' => true],
        ]);
        $form->setSettings(['captcha_type' => 'none', 'allow_multiple' => false]);

        $this->forms->method('findBySlug')->willReturn($form);
        $this->forms->method('hasSubmissionWithEmail')->willReturn(true);

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/forms/submit/contact')
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->withParsedBody(['fields' => ['fullname' => 'Alice', 'email' => 'alice@example.com']]);

        $response = $this->controller->submit($request, (new ResponseFactory())->createResponse(), ['slug' => 'contact']);

        self::assertSame(422, $response->getStatusCode());
        $json = json_decode((string) $response->getBody(), true);
        self::assertIsArray($json);
        self::assertFalse($json['success']);
        self::assertSame('You have already submitted this form.', $json['message']);
        self::assertTrue($json['already_submitted']);
    }

    public function testSubmitSkipsDuplicateCheckWhenFormHasNoEmailField(): void
    {
        $form = new Form('Poll Form', new Slug('poll'));
        $form->setId(43);
        $form->setEnabled(true);
        $form->setFields([
            ['name' => 'favorite_color', 'type' => 'text', 'label' => 'Favorite Color', 'required' => true],
        ]);
        $form->setSettings(['captcha_type' => 'none', 'allow_multiple' => false]);

        $checkedEmails = [];
        $this->forms->method('findBySlug')->willReturn($form);
        $this->forms->method('hasSubmissionWithEmail')->willReturnCallback(
            static function (int $formId, string $email) use (&$checkedEmails): bool {
                $checkedEmails[] = $email;
                return false;
            }
        );

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/forms/submit/poll')
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->withParsedBody(['fields' => ['favorite_color' => 'blue']]);

        $response = $this->controller->submit($request, (new ResponseFactory())->createResponse(), ['slug' => 'poll']);

        self::assertSame([], $checkedEmails);
        self::assertSame(200, $response->getStatusCode());
        $json = json_decode((string) $response->getBody(), true);
        self::assertIsArray($json);
        self::assertTrue($json['success']);
    }

    public function testSubmitSkipsDuplicateCheckWhenMultipleSubmissionsAllowed(): void
    {
        $form = new Form('Contact Form', new Slug('contact'));
        $form->setId(44);
        $form->setEnabled(true);
        $form->setFields([
            ['name' => 'email', 'type' => 'email', 'label' => 'Email Address', 'required' => true],
        ]);
        $form->setSettings(['captcha_type' => 'none', 'allow_multiple' => true]);

        $checkedEmails = [];
        $this->forms->method('findBySlug')->willReturn($form);
        $this->forms->method('hasSubmissionWithEmail')->willReturnCallback(
            static function (int $formId, string $email) use (&$checkedEmails): bool {
                $checkedEmails[] = $email;
                return false;
            }
        );

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/forms/submit/contact')
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->withParsedBody(['fields' => ['email' => 'alice@example.com']]);

        $response = $this->controller->submit($request, (new ResponseFactory())->createResponse(), ['slug' => 'contact']);

        self::assertSame([], $checkedEmails);
        self::assertSame(200, $response->getStatusCode());
        $json = json_decode((string) $response->getBody(), true);
        self::assertIsArray($json);
        self::assertTrue($json['success']);
    }

    public function testSubmitFailsWhenCaptchaValidationFails(): void
    {
        foreach (['hcaptcha', 'recaptcha', 'turnstile'] as $type) {
            $form = new Form('Captcha Form', new Slug('captcha-form'));
            $form->setId(12);
            $form->setEnabled(true);
            $form->setSettings(['captcha_type' => $type]);

            $this->forms->method('findBySlug')->willReturn($form);

            $tokenKey = match ($type) {
                'hcaptcha'  => 'h-captcha-response',
                'recaptcha' => 'g-recaptcha-response',
                'turnstile' => 'cf-turnstile-response',
            };

            // Test with empty token
            $requestEmpty = (new ServerRequestFactory())
                ->createServerRequest('POST', '/forms/submit/captcha-form')
                ->withHeader('X-Requested-With', 'XMLHttpRequest')
                ->withParsedBody([
                    $tokenKey => '',
                    'fields'  => ['email' => 'test@example.com'],
                ]);

            $responseEmpty = $this->controller->submit(
                $requestEmpty,
                (new ResponseFactory())->createResponse(),
                ['slug' => 'captcha-form'],
            );
            self::assertSame(200, $responseEmpty->getStatusCode());
            $jsonEmpty = json_decode((string) $responseEmpty->getBody(), true);
            self::assertFalse($jsonEmpty['success']);
            self::assertSame('Captcha verification failed.', $jsonEmpty['message']);

            // Test with invalid token
            $requestInvalid = (new ServerRequestFactory())
                ->createServerRequest('POST', '/forms/submit/captcha-form')
                ->withHeader('X-Requested-With', 'XMLHttpRequest')
                ->withParsedBody([
                    $tokenKey => 'invalid-token',
                    'fields'  => ['email' => 'test@example.com'],
                ]);

            $responseInvalid = $this->controller->submit(
                $requestInvalid,
                (new ResponseFactory())->createResponse(),
                ['slug' => 'captcha-form'],
            );

            self::assertSame(200, $responseInvalid->getStatusCode());
            $body = (string) $responseInvalid->getBody();
            $json = json_decode($body, true);
            self::assertIsArray($json);
            self::assertFalse($json['success']);
            self::assertSame('Captcha verification failed.', $json['message']);
        }
    }

    public function testShowWithholdsFormWhenCaptchaProviderIsNotConfigured(): void
    {
        $form = new Form('Turnstile Form', new Slug('turnstile-form'));
        $form->setId(21);
        $form->setEnabled(true);
        $form->setSettings(['captcha_type' => 'turnstile']);

        $this->forms->method('findBySlug')->willReturn($form);

        $twig = $this->createMock(Twig::class);
        $twig->expects($this->once())->method('render')
            ->willReturnCallback(function ($response, string $template) {
                self::assertSame('forms/unavailable.twig', $template);
                return $response;
            });

        $this->twig       = $twig;
        $this->controller = $this->buildController();

        $request  = (new ServerRequestFactory())->createServerRequest('GET', '/form/turnstile-form');
        $response = $this->controller->show($request, (new ResponseFactory())->createResponse(), ['slug' => 'turnstile-form']);

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * Rebuild the controller with a real AutomationService backed by a mocked
     * repository exposing one tag_assigned sequence (id 9). Records every
     * enroll() call as [sequenceId, subscriberId] pairs.
     *
     * @param array<int, array{int, int}> $enrolled
     */
    private function rebuildControllerWithTagAutomation(string $triggerTag, array &$enrolled): void
    {
        $sequence = new AutomationSequence('Tag Seq', 'tag_assigned', null, $triggerTag);
        $sequence->setId(9);

        $automationRepo = $this->createStub(AutomationRepositoryInterface::class);
        $automationRepo->method('findAllSequences')->willReturn([$sequence]);
        $automationRepo->method('findStepsBySequenceId')->willReturn([
            new AutomationStep(9, 0, 60, 'Hi', '<p>Hi</p>'),
        ]);
        $automationRepo->method('enroll')->willReturnCallback(
            static function (int $sequenceId, int $subscriberId) use (&$enrolled): void {
                $enrolled[] = [$sequenceId, $subscriberId];
            }
        );

        $this->automationService = new AutomationService(
            $automationRepo,
            $this->subscribers,
            $this->createStub(MailInterface::class),
            $this->createStub(OrderRepositoryInterface::class),
            'http://localhost',
        );
        $this->formAccess = new FormAccessService($this->session, $this->subscribers, $this->settings);
        $this->controller = $this->buildController();
    }

    public function testSubmitSubscribesNewSubscriberWithAutoTags(): void
    {
        $form = new Form('NL Form', new Slug('nl-form'));
        $form->setId(50);
        $form->setEnabled(true);
        $form->setFields([
            ['name' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true],
            ['name' => 'sub', 'type' => 'newsletter_subscribe', 'label' => 'Subscribe', 'tags' => ['vip', 'news']],
        ]);
        $form->setSettings(['captcha_type' => 'none']);

        $this->forms->method('findBySlug')->willReturn($form);

        $saved             = null;
        $this->subscribers = $this->createMock(SubscriberRepositoryInterface::class);
        $this->subscribers->method('findByEmail')->willReturn(null);
        $this->subscribers->expects(self::once())->method('save')
            ->willReturnCallback(static function (Subscriber $s) use (&$saved): Subscriber {
                // Mirror production: the repository assigns an ID on insert
                $s->setId(100);
                return $saved = $s;
            });

        $enrolled = [];
        $this->rebuildControllerWithTagAutomation('vip,news', $enrolled);
        $this->twig->method('render')->willReturn((new ResponseFactory())->createResponse());

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/form/nl-form')
            ->withParsedBody(['fields' => ['email' => 'new@example.com', 'sub' => '1']]);

        $response = $this->controller->submit($request, (new ResponseFactory())->createResponse(), ['slug' => 'nl-form']);

        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull($saved);
        self::assertSame(['vip', 'news'], $saved->getTags());
        self::assertSame([[9, 100], [9, 100]], $enrolled);
    }

    public function testSubmitMergesAutoTagsIntoActiveSubscriber(): void
    {
        $form = new Form('NL Form', new Slug('nl-form'));
        $form->setId(51);
        $form->setEnabled(true);
        $form->setFields([
            ['name' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true],
            ['name' => 'sub', 'type' => 'newsletter_subscribe', 'label' => 'Subscribe', 'tags' => ['vip', 'old']],
        ]);
        $form->setSettings(['captcha_type' => 'none']);

        $existing = new Subscriber('bob@example.com', 'tok123');
        $existing->setId(7);
        $existing->confirm();
        $existing->setTags(['old']);

        $this->forms->method('findBySlug')->willReturn($form);

        $saved             = null;
        $this->subscribers = $this->createMock(SubscriberRepositoryInterface::class);
        $this->subscribers->method('findByEmail')->willReturn($existing);
        $this->subscribers->expects(self::once())->method('save')
            ->willReturnCallback(static function (Subscriber $s) use (&$saved): Subscriber {
                return $saved = $s;
            });

        $enrolled = [];
        $this->rebuildControllerWithTagAutomation('vip', $enrolled);
        $this->twig->method('render')->willReturn((new ResponseFactory())->createResponse());

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/form/nl-form')
            ->withParsedBody(['fields' => ['email' => 'bob@example.com', 'sub' => '1']]);

        $response = $this->controller->submit($request, (new ResponseFactory())->createResponse(), ['slug' => 'nl-form']);

        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull($saved);
        self::assertSame(['old', 'vip'], $saved->getTags());
        // Only the newly added tag fires the automation, not the pre-existing one
        self::assertSame([[9, 7]], $enrolled);
    }

    public function testSubmitSkipsSubscribeWhenFormHasNoEmailField(): void
    {
        $form = new Form('No Email Form', new Slug('no-email'));
        $form->setId(52);
        $form->setEnabled(true);
        $form->setFields([
            ['name' => 'sub', 'type' => 'newsletter_subscribe', 'label' => 'Subscribe', 'tags' => ['vip']],
        ]);
        $form->setSettings(['captcha_type' => 'none']);

        $this->forms->method('findBySlug')->willReturn($form);

        $this->subscribers = $this->createMock(SubscriberRepositoryInterface::class);
        $this->subscribers->expects(self::never())->method('findByEmail');
        $this->subscribers->expects(self::never())->method('save');

        $this->formAccess = new FormAccessService($this->session, $this->subscribers, $this->settings);
        $this->controller = $this->buildController();
        $this->twig->method('render')->willReturn((new ResponseFactory())->createResponse());

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/form/no-email')
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->withParsedBody(['fields' => ['sub' => '1']]);

        $response = $this->controller->submit($request, (new ResponseFactory())->createResponse(), ['slug' => 'no-email']);

        self::assertSame(200, $response->getStatusCode());
        $json = json_decode((string) $response->getBody(), true);
        self::assertIsArray($json);
        self::assertTrue($json['success']);
    }

    public function testSubmitStoresMaskedIpAndUserAgentOnSubmission(): void
    {
        $form = new Form('Contact Form', new Slug('contact'));
        $form->setId(42);
        $form->setEnabled(true);
        $form->setFields([
            ['name' => 'fullname', 'type' => 'text', 'label' => 'Full Name', 'required' => true],
            ['name' => 'email', 'type' => 'email', 'label' => 'Email Address', 'required' => true],
        ]);
        $form->setSettings(['captcha_type' => 'none']);

        $saved = null;
        $forms = $this->createMock(FormRepositoryInterface::class);
        $forms->method('findBySlug')->willReturn($form);
        $forms->expects(self::once())->method('saveSubmission')
            ->willReturnCallback(static function (FormSubmission $submission) use (&$saved): FormSubmission {
                return $saved = $submission;
            });

        $this->forms      = $forms;
        $this->controller = $this->buildController();

        $this->twig->method('render')->willReturn((new ResponseFactory())->createResponse());

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/forms/submit/contact', [
            'REMOTE_ADDR'     => '192.168.1.1',
            'HTTP_USER_AGENT' => 'Vale Test Agent',
        ])->withParsedBody([
            'fields' => [
                'fullname' => 'Alice Smith',
                'email'    => 'alice@example.com',
            ],
        ]);

        $response = $this->controller->submit($request, (new ResponseFactory())->createResponse(), ['slug' => 'contact']);

        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull($saved);
        // ip_privacy_mode defaults to "mask" — the last octet must be redacted
        self::assertSame('192.168.1.***', $saved->getIpAddress());
        self::assertSame('Vale Test Agent', $saved->getUserAgent());
    }

    public function testPreflightExternalAllowedOrigin(): void
    {
        $form = new Form('API Form', new Slug('api-form'));
        $form->setId(100);
        $form->setEnabled(true);
        $form->setSettings([
            'allow_external'  => true,
            'allowed_domains' => ['example.com', 'localhost:3000'],
        ]);

        $forms = $this->createStub(FormRepositoryInterface::class);
        $forms->method('findBySlug')->willReturn($form);

        $this->forms      = $forms;
        $this->controller = $this->buildController();

        $request = (new ServerRequestFactory())
            ->createServerRequest('OPTIONS', '/api/v1/form/api-form')
            ->withHeader('Origin', 'https://sub.example.com');

        $response = $this->controller->preflightExternal(
            $request,
            (new ResponseFactory())->createResponse(),
            ['slug' => 'api-form']
        );

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(
            'https://sub.example.com',
            $response->getHeaderLine('Access-Control-Allow-Origin')
        );
        self::assertSame('POST, OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertSame(
            'Content-Type, Accept',
            $response->getHeaderLine('Access-Control-Allow-Headers')
        );
        self::assertSame('Origin', $response->getHeaderLine('Vary'));
    }

    public function testPreflightExternalDisallowedOrigin(): void
    {
        $form = new Form('API Form', new Slug('api-form'));
        $form->setId(100);
        $form->setEnabled(true);
        $form->setSettings([
            'allow_external'  => true,
            'allowed_domains' => ['example.com'],
        ]);

        $forms = $this->createStub(FormRepositoryInterface::class);
        $forms->method('findBySlug')->willReturn($form);

        $this->forms      = $forms;
        $this->controller = $this->buildController();

        $request = (new ServerRequestFactory())
            ->createServerRequest('OPTIONS', '/api/v1/form/api-form')
            ->withHeader('Origin', 'https://badexample.com');

        $response = $this->controller->preflightExternal(
            $request,
            (new ResponseFactory())->createResponse(),
            ['slug' => 'api-form']
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testSubmitExternalWithPortAllowedDomain(): void
    {
        $form = new Form('API Form', new Slug('api-form'));
        $form->setId(101);
        $form->setEnabled(true);
        $form->setFields([
            ['name' => 'fullname', 'type' => 'text', 'required' => true],
        ]);
        $form->setSettings([
            'allow_external'  => true,
            'allowed_domains' => ['localhost:3000'],
        ]);

        $forms = $this->createStub(FormRepositoryInterface::class);
        $forms->method('findBySlug')->willReturn($form);

        $this->forms      = $forms;
        $this->controller = $this->buildController();

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/form/api-form')
            ->withHeader('Origin', 'http://localhost:3000')
            ->withParsedBody([
                'fields' => ['fullname' => 'John Doe'],
            ]);

        $response = $this->controller->submitExternal(
            $request,
            (new ResponseFactory())->createResponse(),
            ['slug' => 'api-form']
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            'http://localhost:3000',
            $response->getHeaderLine('Access-Control-Allow-Origin')
        );
    }
}
