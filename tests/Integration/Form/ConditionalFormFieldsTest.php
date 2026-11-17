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
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Domain\Shared\Event\EventDispatcherInterface;
use ValeApp\Domain\Shared\Repository\WebhookRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\User\Repository\UserRepositoryInterface;
use ValeApp\Infrastructure\Persistence\DbalFormRepository;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Security\IpAnonymizer;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Theme\ThemeManager;
use ValeApp\Infrastructure\Translation\Translator;

/**
 * Real scenario: a quote request form whose "Company name" field is required but
 * only shown when the visitor picks "Company" in the "I am a" radio field.
 *
 * The test spans the layers that must agree: the rule survives the database
 * round trip, a hidden field is never required, and its value is never stored.
 */
final class ConditionalFormFieldsTest extends TestCase
{
    private const SLUG = 'quote-request';

    private static DbalConnection $db;

    private DbalFormRepository $forms;
    private FormFrontController $controller;

    public static function setUpBeforeClass(): void
    {
        $_ENV['DB_DRIVER'] = 'sqlite';
        putenv('DB_DRIVER=sqlite');

        if (!defined('VL_DB_PREFIX')) {
            define('VL_DB_PREFIX', 'vl_');
        }

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

        $this->forms = new DbalFormRepository(self::$db);

        $twig = $this->createStub(Twig::class);
        $twig->method('render')->willReturn((new ResponseFactory())->createResponse());

        $settings = $this->createStub(DbalSettingsRepository::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key, mixed $default = null) => $default
        );

        $session     = $this->createStub(SessionManager::class);
        $subscribers = $this->createStub(SubscriberRepositoryInterface::class);
        $translator  = $this->createStub(Translator::class);
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
            $subscribers,
            $settings,
            self::$db,
            new IpAnonymizer(),
            new OutboundWebhookService($this->createStub(WebhookRepositoryInterface::class)),
            $this->createStub(EventDispatcherInterface::class),
            $translator,
            $this->createStub(UserRepositoryInterface::class),
            $this->createStub(PlanRepositoryInterface::class),
            new FormAccessService($session, $subscribers, $settings),
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

    private function createConditionalForm(): Form
    {
        $form = new Form('Quote Request', new Slug(self::SLUG));
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
                'type'       => 'text',
                'label'      => 'Company name',
                'name'       => 'company_name',
                'required'   => true,
                'width'      => '100',
                'conditions' => [
                    'logic' => 'all',
                    'rules' => [
                        ['field' => 'request_type', 'operator' => 'equals', 'value' => 'company'],
                    ],
                ],
            ],
            [
                'type'     => 'textarea',
                'label'    => 'Message',
                'name'     => 'message',
                'required' => true,
                'width'    => '100',
            ],
        ]);
        $form->setSettings(['captcha_type' => 'none', 'allow_external' => true]);

        $this->forms->save($form);

        return $form;
    }

    private function submitExternal(array $fields): \Psr\Http\Message\ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/form/' . self::SLUG)
            ->withParsedBody(['fields' => $fields]);

        return $this->controller->submitExternal(
            $request,
            (new ResponseFactory())->createResponse(),
            ['slug' => self::SLUG],
        );
    }

    public function testConditionSurvivesDatabaseRoundTrip(): void
    {
        $form     = $this->createConditionalForm();
        $reloaded = $this->forms->findBySlug(new Slug(self::SLUG));

        self::assertNotNull($reloaded);
        self::assertSame($form->getId(), $reloaded->getId());

        $fields = $reloaded->getFields();
        self::assertCount(3, $fields);
        self::assertSame(
            [['field' => 'request_type', 'operator' => 'equals', 'value' => 'company']],
            $fields[1]['conditions']['rules']
        );
        self::assertArrayNotHasKey('conditions', $fields[0]);
        self::assertArrayNotHasKey('conditions', $fields[2]);

        self::assertFalse($reloaded->isFieldVisible($fields[1], ['request_type' => 'individual']));
        self::assertTrue($reloaded->isFieldVisible($fields[1], ['request_type' => 'company']));
    }

    public function testExternalSubmissionSkipsHiddenRequiredField(): void
    {
        $form = $this->createConditionalForm();

        $response = $this->submitExternal([
            'request_type' => 'individual',
            'message'      => 'Please send pricing.',
        ]);

        self::assertSame(200, $response->getStatusCode());
        $json = json_decode((string) $response->getBody(), true);
        self::assertIsArray($json);
        self::assertTrue($json['success']);

        $submissions = $this->forms->findAllSubmissions($form->getId());
        self::assertCount(1, $submissions);

        $data = $submissions[0]->getData();
        self::assertSame('individual', $data['request_type']);
        self::assertSame('Please send pricing.', $data['message']);
        self::assertArrayNotHasKey('company_name', $data);
    }

    public function testExternalSubmissionRequiresConditionalFieldOnceShown(): void
    {
        $form = $this->createConditionalForm();

        $response = $this->submitExternal([
            'request_type' => 'company',
            'message'      => 'Need a quote.',
        ]);

        self::assertSame(422, $response->getStatusCode());
        $json = json_decode((string) $response->getBody(), true);
        self::assertIsArray($json);
        self::assertFalse($json['success']);
        self::assertStringContainsString('Company name', (string) $json['message']);

        self::assertCount(0, $this->forms->findAllSubmissions($form->getId()));
    }

    public function testExternalSubmissionStoresConditionalValueOnceShown(): void
    {
        $form = $this->createConditionalForm();

        $response = $this->submitExternal([
            'request_type' => 'company',
            'company_name' => 'Acme Ltd',
            'message'      => 'Need a quote.',
        ]);

        self::assertSame(200, $response->getStatusCode());

        $submissions = $this->forms->findAllSubmissions($form->getId());
        self::assertCount(1, $submissions);
        self::assertSame('Acme Ltd', $submissions[0]->getData()['company_name']);
    }

    public function testStandalonePageOmitsHiddenFieldFromSubmission(): void
    {
        $form = $this->createConditionalForm();

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/form/' . self::SLUG)
            ->withParsedBody([
                'fields' => [
                    'request_type' => 'individual',
                    'message'      => 'Hello there.',
                ],
            ]);

        $response = $this->controller->submit(
            $request,
            (new ResponseFactory())->createResponse(),
            ['slug' => self::SLUG],
        );

        self::assertSame(200, $response->getStatusCode());

        $submissions = $this->forms->findAllSubmissions($form->getId());
        self::assertCount(1, $submissions);
        self::assertArrayNotHasKey('company_name', $submissions[0]->getData());
    }
}
