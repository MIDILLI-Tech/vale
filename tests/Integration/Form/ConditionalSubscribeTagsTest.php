<?php

declare(strict_types=1);

namespace ValeApp\Tests\Integration\Form;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\DriverManager;
use Phinx\Config\Config;
use Phinx\Migration\Manager;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\Twig;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use ValeApp\App\Controllers\Front\FormFrontController;
use ValeApp\Application\Form\FormAccessService;
use ValeApp\Application\Form\FormCaptchaService;
use ValeApp\Application\Mail\MailService;
use ValeApp\Application\Webhook\OutboundWebhookService;
use ValeApp\Domain\Form\Entity\Form;
use ValeApp\Domain\Mail\Port\MailInterface;
use ValeApp\Domain\Membership\Repository\PlanRepositoryInterface;
use ValeApp\Domain\Shared\Event\EventDispatcherInterface;
use ValeApp\Domain\Shared\Repository\WebhookRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\User\Repository\UserRepositoryInterface;
use ValeApp\Infrastructure\Persistence\DbalFormRepository;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Persistence\DbalSubscriberRepository;
use ValeApp\Infrastructure\Security\IpAnonymizer;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Theme\ThemeManager;
use ValeApp\Infrastructure\Translation\Translator;

/**
 * Real scenario: a newsletter form whose subscribe field assigns "b2b" only to
 * visitors who answered "company" in the "I am a" radio field.
 *
 * The test covers the two layers that must agree: the rule survives the
 * database round trip, and a submission stores exactly the tags its answers
 * justify — no more, no less.
 */
final class ConditionalSubscribeTagsTest extends TestCase
{
    private const SLUG = 'tagged-newsletter';

    private static DbalConnection $db;

    private DbalFormRepository $forms;
    private DbalSubscriberRepository $subscribers;
    private FormFrontController $controller;

    public static function setUpBeforeClass(): void
    {
        $_ENV['DB_DRIVER'] = 'sqlite';
        putenv('DB_DRIVER=sqlite');

        if (!defined('VL_DB_PREFIX')) {
            define('VL_DB_PREFIX', 'vl_');
        }

        // The confirmation mail is best-effort, but the base URL is resolved
        // before it, so the helper must have a valid APP_URL.
        $_ENV['APP_URL'] = 'https://example.test';
        putenv('APP_URL=https://example.test');

        self::$db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $config                                = Config::fromPhp(dirname(__DIR__, 3) . '/phinx.php');
        $environments                          = $config['environments'];
        $environments['default']['connection'] = self::$db->getNativeConnection();
        $config['environments']                = $environments;

        $manager = new Manager($config, new ArrayInput([]), new NullOutput());
        $manager->migrate('default');
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$db)) {
            try {
                self::$db->close();
            } catch (\Throwable) {
            }
        }
    }

    protected function setUp(): void
    {
        self::$db->beginTransaction();

        $this->forms       = new DbalFormRepository(self::$db);
        $this->subscribers = new DbalSubscriberRepository(self::$db);

        $twig = $this->createStub(Twig::class);
        $twig->method('render')->willReturn((new ResponseFactory())->createResponse());

        $settings = $this->createStub(DbalSettingsRepository::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key, mixed $default = null) => $default
        );

        $session    = $this->createStub(SessionManager::class);
        $translator = $this->createStub(Translator::class);
        $translator->method('get')->willReturn('New form submission');

        $this->controller = new FormFrontController(
            $twig,
            $this->forms,
            new MailService(
                $this->createStub(MailInterface::class),
                $twig,
                $settings,
                $translator,
                $this->createStub(ThemeManager::class),
            ),
            $session,
            $this->subscribers,
            $settings,
            self::$db,
            new IpAnonymizer(),
            new OutboundWebhookService($this->createStub(WebhookRepositoryInterface::class)),
            $this->createStub(EventDispatcherInterface::class),
            $translator,
            $this->createStub(UserRepositoryInterface::class),
            $this->createStub(PlanRepositoryInterface::class),
            new FormAccessService($session, $this->subscribers, $settings),
            new FormCaptchaService($this->forms, $settings),
        );
    }

    protected function tearDown(): void
    {
        try {
            self::$db->rollBack();
        } catch (\Throwable) {
        }
    }

    private function createTaggedForm(): Form
    {
        $form = new Form('Tagged Newsletter', new Slug(self::SLUG));
        $form->setEnabled(true);
        $form->setFields([
            [
                'type'     => 'radio',
                'label'    => 'I am a',
                'name'     => 'request_type',
                'required' => true,
                'width'    => '100',
                'options'  => [
                    ['value' => 'company', 'title' => ''],
                    ['value' => 'individual', 'title' => ''],
                ],
            ],
            [
                'type'     => 'email',
                'label'    => 'Email',
                'name'     => 'email',
                'required' => false,
                'width'    => '100',
            ],
            [
                'type'      => 'newsletter_subscribe',
                'label'     => 'Subscribe to the newsletter',
                'name'      => 'sub',
                'width'     => '100',
                'tags'      => ['newsletter'],
                'tag_rules' => [
                    ['field' => 'request_type', 'operator' => 'equals', 'value' => 'company', 'tags' => ['b2b']],
                ],
            ],
        ]);
        $form->setSettings(['captcha_type' => 'none', 'allow_external' => true]);

        $this->forms->save($form);

        return $form;
    }

    private function submitExternal(array $fields): \Psr\Http\Message\ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/form/' . self::SLUG)
            ->withParsedBody(['fields' => $fields]);

        return $this->controller->submitExternal(
            $request,
            (new ResponseFactory())->createResponse(),
            ['slug' => self::SLUG],
        );
    }

    public function testTagRulesSurviveDatabaseRoundTrip(): void
    {
        $form     = $this->createTaggedForm();
        $reloaded = $this->forms->findBySlug(new Slug(self::SLUG));

        self::assertNotNull($reloaded);
        self::assertSame($form->getId(), $reloaded->getId());

        $fields = $reloaded->getFields();
        self::assertSame(['newsletter'], $fields[2]['tags']);
        self::assertSame(
            [['field' => 'request_type', 'operator' => 'equals', 'value' => 'company', 'tags' => ['b2b']]],
            $fields[2]['tag_rules']
        );
    }

    public function testMatchingRuleAddsTagToNewSubscriber(): void
    {
        $this->createTaggedForm();

        $response = $this->submitExternal([
            'request_type' => 'company',
            'email'        => 'buyer@example.com',
            'sub'          => '1',
        ]);

        self::assertSame(200, $response->getStatusCode());

        $subscriber = $this->subscribers->findByEmail('buyer@example.com');
        self::assertNotNull($subscriber);
        // The repository returns tags in database order, not assignment order.
        self::assertEqualsCanonicalizing(['newsletter', 'b2b'], $subscriber->getTags());
    }

    public function testNonMatchingAnswerKeepsOnlyStaticTags(): void
    {
        $this->createTaggedForm();

        $response = $this->submitExternal([
            'request_type' => 'individual',
            'email'        => 'reader@example.com',
            'sub'          => '1',
        ]);

        self::assertSame(200, $response->getStatusCode());

        $subscriber = $this->subscribers->findByEmail('reader@example.com');
        self::assertNotNull($subscriber);
        self::assertSame(['newsletter'], $subscriber->getTags());
    }

    public function testUncheckedSubscribeFieldDoesNotCreateSubscriber(): void
    {
        $this->createTaggedForm();

        $this->submitExternal([
            'request_type' => 'company',
            'email'        => 'silent@example.com',
        ]);

        self::assertNull($this->subscribers->findByEmail('silent@example.com'));
    }
}
