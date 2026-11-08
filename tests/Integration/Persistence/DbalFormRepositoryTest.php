<?php

declare(strict_types=1);

namespace ValeApp\Tests\Integration\Persistence;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\DriverManager;
use Phinx\Config\Config;
use Phinx\Migration\Manager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use ValeApp\Domain\Form\Entity\FormSubmission;
use ValeApp\Infrastructure\Persistence\DbalFormRepository;

/**
 * The submitter_email column backing email-based duplicate detection must be
 * populated on save and matched only against the form's email field.
 */
final class DbalFormRepositoryTest extends TestCase
{
    private static DbalConnection $db;

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
    }

    protected function tearDown(): void
    {
        try {
            self::$db->rollBack();
        } catch (\Throwable) {
        }
    }

    private function repo(): DbalFormRepository
    {
        return new DbalFormRepository(self::$db);
    }

    private function createForm(string $slug): int
    {
        self::$db->insert(VL_DB_PREFIX . 'forms', [
            'name'     => 'Test Form',
            'slug'     => $slug,
            'fields'   => json_encode([]),
            'settings' => json_encode([]),
        ]);

        return (int) self::$db->lastInsertId();
    }

    private function submit(int $formId, array $data): int
    {
        $submission = new FormSubmission($formId, $data);
        $this->repo()->saveSubmission($submission);

        return (int) $submission->getId();
    }

    private function submitterEmailOf(int $submissionId): ?string
    {
        $value = self::$db->fetchOne(
            'SELECT submitter_email FROM ' . VL_DB_PREFIX . 'form_submissions WHERE id = ?',
            [$submissionId]
        );
        if ($value === false || $value === null) {
            return null;
        }

        return (string) $value;
    }

    public function testSaveSubmissionStoresNormalizedSubmitterEmail(): void
    {
        $formId = $this->createForm('contact');
        $id     = $this->submit($formId, ['name' => 'Alice', 'email' => '  Alice@Example.COM ']);

        self::assertSame('alice@example.com', $this->submitterEmailOf($id));
    }

    public function testSaveSubmissionWithoutEmailLeavesSubmitterEmailNull(): void
    {
        $formId = $this->createForm('poll');
        $id     = $this->submit($formId, ['favorite_color' => 'blue']);

        self::assertNull($this->submitterEmailOf($id));
    }

    public function testHasSubmissionWithEmailMatchesCaseInsensitively(): void
    {
        $formId = $this->createForm('contact');
        $this->submit($formId, ['email' => 'Alice@Example.com']);
        $repo = $this->repo();

        self::assertTrue($repo->hasSubmissionWithEmail($formId, 'alice@example.com'));
        self::assertTrue($repo->hasSubmissionWithEmail($formId, 'ALICE@EXAMPLE.COM'));
        self::assertFalse($repo->hasSubmissionWithEmail($formId, 'bob@example.com'));
        self::assertFalse($repo->hasSubmissionWithEmail($formId, '   '));
    }

    public function testHasSubmissionWithEmailIgnoresNonEmailFields(): void
    {
        $formId = $this->createForm('survey');
        $this->submit($formId, ['name' => 'Alice', 'manager_email' => 'manager@example.com']);
        $repo = $this->repo();

        self::assertFalse($repo->hasSubmissionWithEmail($formId, 'manager@example.com'));
    }

    public function testHasSubmissionWithEmailIsScopedToForm(): void
    {
        $formId      = $this->createForm('contact');
        $otherFormId = $this->createForm('newsletter');
        $this->submit($formId, ['email' => 'alice@example.com']);

        self::assertFalse($this->repo()->hasSubmissionWithEmail($otherFormId, 'alice@example.com'));
    }
}
