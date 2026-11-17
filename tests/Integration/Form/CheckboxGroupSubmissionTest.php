<?php

declare(strict_types=1);

namespace ValeApp\Tests\Integration\Form;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\DriverManager;
use Phinx\Config\Config;
use Phinx\Migration\Manager;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
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
 * A checkbox field with options renders as a group (`fields[NAME][]`), so the
 * server receives a list. This scenario proves the list survives the whole
 * path: controller -> submission entity -> JSON column -> reload.
 */
final class CheckboxGroupSubmissionTest extends TestCase
{
    private const SLUG = 'preferences';

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

    private function createForm(): Form
    {
        $form = new Form('Preferences', new Slug(self::SLUG));
        $form->setEnabled(true);
        $form->setFields([
            [
                'type'     => 'checkbox',
                'label'    => 'Flavors',
                'name'     => 'flavors',
                'required' => true,
                'width'    => '100',
                'options'  => [
                    ['value' => 'Vanilla', 'title' => ''],
                    ['value' => 'Chocolate', 'title' => ''],
                    ['value' => 'Strawberry', 'title' => ''],
                ],
            ],
            [
                'type'       => 'checkbox',
                'label'      => 'Extras',
                'name'       => 'extras',
                'required'   => false,
                'width'      => '100',
                'options'    => [
                    ['value' => 'Sprinkles', 'title' => ''],
                    ['value' => 'Sauce', 'title' => ''],
                ],
                'conditions' => [
                    'logic' => 'all',
                    'rules' => [
                        ['field' => 'flavors', 'operator' => 'contains', 'value' => 'Chocolate'],
                    ],
                ],
            ],
            [
                'type'     => 'textarea',
                'label'    => 'Notes',
                'name'     => 'notes',
                'required' => false,
                'width'    => '100',
            ],
        ]);
        $form->setSettings(['captcha_type' => 'none', 'allow_external' => true]);

        $this->forms->save($form);

        return $form;
    }

    /** @param array<string, mixed> $fields */
    private function submitExternal(array $fields): ResponseInterface
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

    /** @return array<int, array<string, mixed>> decoded `data` column, newest first */
    private function rawStoredData(int $formId): array
    {
        $rows = self::$db->fetchAllAssociative(
            'SELECT data FROM ' . VL_DB_PREFIX . 'form_submissions WHERE form_id = ? ORDER BY id DESC',
            [$formId],
        );

        return array_map(static fn (array $row) => json_decode((string) $row['data'], true), $rows);
    }

    public function testMultipleCheckedValuesAreStoredAsArray(): void
    {
        $form = $this->createForm();

        $response = $this->submitExternal([
            'flavors' => ['Vanilla', 'Strawberry'],
            'notes'   => 'No nuts, please.',
        ]);

        self::assertSame(200, $response->getStatusCode());

        $submissions = $this->forms->findAllSubmissions($form->getId());
        self::assertCount(1, $submissions);

        $data = $submissions[0]->getData();
        self::assertIsArray($data['flavors']);
        self::assertSame(['Vanilla', 'Strawberry'], $data['flavors']);
        self::assertSame('No nuts, please.', $data['notes']);

        $raw = $this->rawStoredData($form->getId());
        self::assertSame(['Vanilla', 'Strawberry'], $raw[0]['flavors']);
    }

    public function testSingleCheckedValueKeepsListShape(): void
    {
        $form = $this->createForm();

        $response = $this->submitExternal(['flavors' => ['Chocolate']]);

        self::assertSame(200, $response->getStatusCode());

        $data = $this->forms->findAllSubmissions($form->getId())[0]->getData();
        self::assertIsArray($data['flavors']);
        self::assertSame(['Chocolate'], $data['flavors']);
    }

    public function testConditionalGroupIsAcceptedWhenItsTriggerMatches(): void
    {
        $form = $this->createForm();

        $response = $this->submitExternal([
            'flavors' => ['Chocolate'],
            'extras'  => ['Sprinkles', 'Sauce'],
        ]);

        self::assertSame(200, $response->getStatusCode());

        $data = $this->forms->findAllSubmissions($form->getId())[0]->getData();
        self::assertSame(['Sprinkles', 'Sauce'], $data['extras']);

        $raw = $this->rawStoredData($form->getId());
        self::assertSame(['Sprinkles', 'Sauce'], $raw[0]['extras']);
    }

    public function testValuesForHiddenGroupAreNotStored(): void
    {
        $form = $this->createForm();

        $response = $this->submitExternal([
            'flavors' => ['Vanilla'],
            'extras'  => ['Sprinkles'],
        ]);

        self::assertSame(200, $response->getStatusCode());

        $data = $this->forms->findAllSubmissions($form->getId())[0]->getData();
        self::assertSame(['Vanilla'], $data['flavors']);
        self::assertArrayNotHasKey('extras', $data);

        $raw = $this->rawStoredData($form->getId());
        self::assertArrayNotHasKey('extras', $raw[0]);
    }

    public function testRequiredGroupRejectsEmptySelection(): void
    {
        $form = $this->createForm();

        $response = $this->submitExternal([
            'flavors' => [],
            'notes'   => 'Nothing picked.',
        ]);

        self::assertSame(422, $response->getStatusCode());
        $json = json_decode((string) $response->getBody(), true);
        self::assertIsArray($json);
        self::assertFalse($json['success']);
        self::assertStringContainsString('Flavors', (string) $json['message']);

        self::assertCount(0, $this->forms->findAllSubmissions($form->getId()));
    }
}
