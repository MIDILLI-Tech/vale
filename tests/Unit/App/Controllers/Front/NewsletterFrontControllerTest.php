<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Controllers\Front;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\Twig;
use ValeApp\App\Controllers\Front\NewsletterFrontController;
use ValeApp\Application\Mail\MailService;
use ValeApp\Application\Newsletter\AutomationService;
use ValeApp\Domain\Mail\Port\MailInterface;
use ValeApp\Domain\Newsletter\Entity\Subscriber;
use ValeApp\Domain\Newsletter\Repository\AutomationRepositoryInterface;
use ValeApp\Domain\Newsletter\Repository\CampaignRepositoryInterface;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderRepositoryInterface;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Theme\ThemeManager;
use ValeApp\Infrastructure\Translation\Translator;

final class NewsletterFrontControllerTest extends TestCase
{
    private const TOKEN = 'abc123def456';

    private $twig;
    private $subscribers;
    private $campaigns;
    private $mail;
    private $session;
    private $automationService;
    private $translator;
    private $settings;

    protected function setUp(): void
    {
        $this->twig        = $this->createStub(Twig::class);
        $this->subscribers = $this->createStub(SubscriberRepositoryInterface::class);
        $this->campaigns   = $this->createStub(CampaignRepositoryInterface::class);
        $this->session     = $this->createStub(SessionManager::class);
        $this->translator  = $this->createStub(Translator::class);
        $this->translator->method('get')->willReturnCallback(
            static fn (string $text): string => $text
        );

        $mailer         = $this->createStub(MailInterface::class);
        $this->settings = $this->createStub(DbalSettingsRepository::class);
        $this->mail     = new MailService(
            $mailer,
            $this->twig,
            $this->settings,
            $this->translator,
            $this->createStub(ThemeManager::class)
        );

        $this->automationService = new AutomationService(
            $this->createStub(AutomationRepositoryInterface::class),
            $this->subscribers,
            $mailer,
            $this->createStub(OrderRepositoryInterface::class),
            'http://localhost'
        );
    }

    private function buildController(?array $sessionMap = null): NewsletterFrontController
    {
        if ($sessionMap !== null) {
            $this->session->method('get')->willReturnCallback(
                static fn (string $key, mixed $default = null) => $sessionMap[$key] ?? $default
            );
        }

        return new NewsletterFrontController(
            $this->twig,
            $this->subscribers,
            $this->campaigns,
            $this->mail,
            $this->session,
            $this->automationService,
            $this->translator,
            $this->settings
        );
    }

    public function testPostUnsubscribeReturns200PlainText(): void
    {
        $subscriber = new Subscriber('reader@example.com', self::TOKEN);
        $this->subscribers->method('findByToken')->willReturn($subscriber);

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/newsletter/unsubscribe/' . self::TOKEN);

        $response = $this->buildController()->unsubscribe(
            $request,
            (new ResponseFactory())->createResponse(),
            ['token' => self::TOKEN]
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/plain', $response->getHeaderLine('Content-Type'));
        self::assertSame('unsubscribed', $subscriber->getStatus());
    }

    public function testPostUnsubscribeWithUnknownTokenStillReturns200(): void
    {
        $this->subscribers->method('findByToken')->willReturn(null);

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/newsletter/unsubscribe/deadbeef');

        $response = $this->buildController()->unsubscribe(
            $request,
            (new ResponseFactory())->createResponse(),
            ['token' => 'deadbeef']
        );

        self::assertSame(200, $response->getStatusCode());
    }

    public function testGetUnsubscribeRendersTemplateWithSuccessFlag(): void
    {
        $subscriber = new Subscriber('reader@example.com', self::TOKEN);
        $this->subscribers->method('findByToken')->willReturn($subscriber);

        $rendered = [];
        $this->twig->method('render')->willReturnCallback(
            static function ($response, string $template, array $data) use (&$rendered) {
                $rendered = ['template' => $template, 'data' => $data];
                return $response;
            }
        );

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/newsletter/unsubscribe/' . self::TOKEN);

        $response = $this->buildController()->unsubscribe(
            $request,
            (new ResponseFactory())->createResponse(),
            ['token' => self::TOKEN]
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('newsletter/unsubscribe.twig', $rendered['template']);
        self::assertTrue($rendered['data']['success']);
        self::assertSame('unsubscribed', $subscriber->getStatus());
    }

    public function testTrackClickSanitizesControlCharactersAndCrlf(): void
    {
        $controller      = $this->buildController();
        $responseFactory = new ResponseFactory();

        // URL containing CRLF and control characters
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/newsletter/click/abc123')
            ->withQueryParams(['url' => "https://example.com/path\r\nHeader-Injection: 1\0"]);

        $response = $controller->trackClick(
            $request,
            $responseFactory->createResponse(),
            ['token' => 'abc123']
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(
            'https://example.com/pathHeader-Injection: 1',
            $response->getHeaderLine('Location')
        );
    }

    public function testSubscribeRejectsHoneypotSpam(): void
    {
        $controller = $this->buildController([
            'newsletter_captcha_type'  => 'honeypot',
            'newsletter_honeypot_name' => '_hp_name',
        ]);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/newsletter/subscribe')
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->withParsedBody([
                'email'    => 'bot@example.com',
                '_hp_name' => 'I am a bot',
            ]);

        $response = $controller->subscribe($request, (new ResponseFactory())->createResponse());

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertFalse($body['success']);
        self::assertSame('Spam detected.', $body['message']);
    }

    public function testSubscribeRejectsCustomHoneypotFieldSpam(): void
    {
        $controller = $this->buildController([
            'newsletter_captcha_type'  => 'honeypot',
            'newsletter_honeypot_name' => 'company_website',
        ]);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/newsletter/subscribe')
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->withParsedBody([
                'email'            => 'bot@example.com',
                'company_website'  => 'I am a bot',
            ]);

        $response = $controller->subscribe($request, (new ResponseFactory())->createResponse());

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertFalse($body['success']);
        self::assertSame('Spam detected.', $body['message']);
    }

    public function testSubscribeRejectsIncorrectMathCaptcha(): void
    {
        $controller = $this->buildController([
            'newsletter_captcha_type'   => 'math',
            'newsletter_captcha_answer' => 10,
        ]);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/newsletter/subscribe')
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->withParsedBody([
                'email'          => 'user@example.com',
                'captcha_answer' => '5',
            ]);

        $response = $controller->subscribe($request, (new ResponseFactory())->createResponse());

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertFalse($body['success']);
        self::assertSame('Incorrect captcha answer. Please try again.', $body['message']);
    }

    public function testSubscribeIgnoresBodyCaptchaTypeDowngrade(): void
    {
        // The session says turnstile, but the attacker claims "disabled" in
        // the body and sends no token. Validation must still run and fail.
        $controller = $this->buildController(['newsletter_captcha_type' => 'turnstile']);
        $request    = (new ServerRequestFactory())
            ->createServerRequest('POST', '/newsletter/subscribe')
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->withParsedBody([
                'email'        => 'bot@example.com',
                'captcha_type' => 'disabled',
            ]);

        $response = $controller->subscribe($request, (new ResponseFactory())->createResponse());

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertFalse($body['success']);
        self::assertSame('Captcha verification failed: Missing captcha response.', $body['message']);
    }

    public function testSubscribeIgnoresBodyCaptchaTypeWhenSessionHasNone(): void
    {
        // No rendered form in this session, but the body smuggles a honeypot
        // tripwire. Server-side state wins: the request must NOT be treated
        // as spam — it falls through to email validation instead.
        $controller = $this->buildController(['newsletter_captcha_type' => 'disabled']);
        $request    = (new ServerRequestFactory())
            ->createServerRequest('POST', '/newsletter/subscribe')
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->withParsedBody([
                'email'        => 'not-an-email',
                'captcha_type' => 'honeypot',
                '_hp_name'     => 'I am a bot',
            ]);

        $response = $controller->subscribe($request, (new ResponseFactory())->createResponse());

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertFalse($body['success']);
        self::assertSame('Please enter a valid email address.', $body['message']);
    }

    public function testSubscribeSplitsCommaSeparatedTags(): void
    {
        $saved = null;
        $this->subscribers->method('findByEmail')->willReturn(null);
        $this->subscribers->method('save')->willReturnCallback(
            static function (Subscriber $subscriber) use (&$saved): Subscriber {
                $saved = $subscriber;
                return $subscriber;
            }
        );
        // Skip the confirmation email: the Twig/Mailer test doubles cannot render it.
        $this->settings->method('get')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => $key === 'mail_notification_newsletter-confirmation_customer' ? '0' : $default
        );

        $controller = $this->buildController(['newsletter_captcha_type' => 'disabled']);
        $request    = (new ServerRequestFactory())
            ->createServerRequest('POST', '/newsletter/subscribe')
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->withParsedBody([
                'email' => 'user@example.com',
                'tag'   => 'vip, promo ,vip',
            ]);

        $response = $controller->subscribe($request, (new ResponseFactory())->createResponse());

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertTrue($body['success']);
        self::assertNotNull($saved);
        self::assertSame(['vip', 'promo'], $saved->getTags());
    }

    public function testSubscribeAppendsTagsToExistingOnes(): void
    {
        $existing = new Subscriber('user@example.com', 'existingtoken123');
        $existing->setTags(['old']);

        $saved = null;
        $this->subscribers->method('findByEmail')->willReturn($existing);
        $this->subscribers->method('save')->willReturnCallback(
            static function (Subscriber $subscriber) use (&$saved): Subscriber {
                $saved = $subscriber;
                return $subscriber;
            }
        );
        // Skip the confirmation email: the Twig/Mailer test doubles cannot render it.
        $this->settings->method('get')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => $key === 'mail_notification_newsletter-confirmation_customer' ? '0' : $default
        );

        $controller = $this->buildController(['newsletter_captcha_type' => 'disabled']);
        $request    = (new ServerRequestFactory())
            ->createServerRequest('POST', '/newsletter/subscribe')
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->withParsedBody([
                'email' => 'user@example.com',
                'tag'   => 'vip',
            ]);

        $response = $controller->subscribe($request, (new ResponseFactory())->createResponse());

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertTrue($body['success']);
        self::assertNotNull($saved);
        self::assertSame(['old', 'vip'], $saved->getTags());
    }
}
