<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Controllers\Admin;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use ValeApp\App\Controllers\Admin\FormController;
use ValeApp\Application\Form\FormCaptchaService;
use ValeApp\Domain\Form\Entity\Form;
use ValeApp\Domain\Form\Entity\FormSubmission;
use ValeApp\Domain\Form\Repository\FormRepositoryInterface;
use ValeApp\Domain\Membership\Repository\PlanRepositoryInterface;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Infrastructure\Content\SlugService;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

final class FormSubmissionsExportTest extends TestCase
{
    /** @param FormSubmission[] $submissions */
    private function exportController(Form $form, array $submissions): FormController
    {
        $settings = $this->createStub(DbalSettingsRepository::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => $key === 'multi_language_content' ? '0' : $default,
        );

        $forms = $this->createStub(FormRepositoryInterface::class);
        $forms->method('findById')->willReturn($form);
        $forms->method('findAllSubmissionsForForms')->willReturn($submissions);

        return new FormController(
            $this->createStub(Translator::class),
            $this->createStub(Twig::class),
            $forms,
            $this->createStub(SlugService::class),
            $this->createStub(SessionManager::class),
            $settings,
            $this->createStub(PlanRepositoryInterface::class),
            new FormCaptchaService($forms, $settings),
            $this->createStub(SubscriberRepositoryInterface::class),
        );
    }

    private function contactForm(): Form
    {
        $form = new Form('Contact', new Slug('contact'));
        $form->setId(7);
        $form->setLanguage('en');
        $form->setFields([
            ['type' => 'text', 'name' => 'name', 'label' => 'Name'],
            ['type' => 'email', 'name' => 'email', 'label' => 'Email'],
            ['type' => 'checkbox', 'name' => 'topics', 'label' => 'Topics'],
            ['type' => 'static_text', 'name' => '', 'label' => 'Intro copy'],
        ]);

        return $form;
    }

    private function submission(): FormSubmission
    {
        return FormSubmission::fromRow([
            'id'         => 11,
            'form_id'    => 7,
            'data'       => json_encode([
                'name'   => '=SUM(A1:A2)',
                'email'  => 'alice@example.com',
                'topics' => ['news', 'deals'],
            ]),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'is_read'    => 1,
            'created_at' => '2026-11-20 10:00:00',
        ]);
    }

    private function request(): ServerRequestInterface
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['lang' => 'all']);

        return $request;
    }

    public function testCsvExportIncludesFieldsAndEscapesFormulas(): void
    {
        $controller = $this->exportController($this->contactForm(), [$this->submission()]);

        $response = $controller->exportSubmissionsCsv($this->request(), new Response(), ['id' => '7']);
        $csv      = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/csv', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('form-contact-submissions-', $response->getHeaderLine('Content-Disposition'));
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        self::assertStringContainsString('ID,Form,Language,Name,Email,Topics,"Submitted At","IP Address","User Agent",Read', $csv);
        self::assertStringContainsString("11,Contact,en,'=SUM(A1:A2),alice@example.com", $csv);
        self::assertStringContainsString('"news, deals"', $csv);
        self::assertStringContainsString('2026-11-20 10:00:00', $csv);
        self::assertStringNotContainsString('Intro copy', $csv);
    }

    public function testJsonExportContainsSubmissionDataAndFields(): void
    {
        $controller = $this->exportController($this->contactForm(), [$this->submission()]);

        $response = $controller->exportSubmissionsJson($this->request(), new Response(), ['id' => '7']);
        $payload  = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('form-contact-submissions-', $response->getHeaderLine('Content-Disposition'));
        self::assertSame('contact', $payload['form']['slug']);
        self::assertSame([
            ['name' => 'name', 'label' => 'Name'],
            ['name' => 'email', 'label' => 'Email'],
            ['name' => 'topics', 'label' => 'Topics'],
        ], $payload['fields']);
        self::assertSame('=SUM(A1:A2)', $payload['submissions'][0]['data']['name']);
        self::assertSame('alice@example.com', $payload['submissions'][0]['data']['email']);
        self::assertSame(['news', 'deals'], $payload['submissions'][0]['data']['topics']);
        self::assertTrue($payload['submissions'][0]['is_read']);
        self::assertSame('127.0.0.1', $payload['submissions'][0]['ip_address']);
    }
}
