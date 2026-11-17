<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Domain\Form\Entity;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Form\Entity\Form;
use ValeApp\Domain\Shared\ValueObject\Slug;

final class FormTest extends TestCase
{
    public function testConstructorDefaults(): void
    {
        $form = new Form('Contact', new Slug('contact'));

        self::assertSame('Contact', $form->getName());
        self::assertSame('contact', (string) $form->getSlug());
        self::assertSame([], $form->getFields());
        self::assertSame([], $form->getSettings());
        self::assertNull($form->getNotifyEmail());
        self::assertSame('Submit', $form->getSubmitLabel());
        self::assertNull($form->getSuccessMessage());
        self::assertTrue($form->isEnabled());
        self::assertSame(0, $form->getSubmissionCount());
        self::assertSame('en', $form->getLanguage());
        self::assertNull($form->getTranslationOf());
    }

    public function testSetters(): void
    {
        $form = new Form('Contact', new Slug('contact'));

        $form->setName('Feedback');
        $form->setSlug(new Slug('feedback'));
        $form->setFields([['type' => 'text', 'label' => 'Name']]);
        $form->setSettings(['redirect' => '/thanks']);
        $form->setNotifyEmail('admin@test.com');
        $form->setSubmitLabel('Send');
        $form->setSuccessMessage('Thanks!');
        $form->setEnabled(false);
        $form->setSubmissionCount(42);
        $form->setLanguage('tr');
        $form->setTranslationOf(5);

        self::assertSame('Feedback', $form->getName());
        self::assertSame('feedback', (string) $form->getSlug());
        self::assertCount(1, $form->getFields());
        self::assertSame(['redirect' => '/thanks'], $form->getSettings());
        self::assertSame('admin@test.com', $form->getNotifyEmail());
        self::assertSame('Send', $form->getSubmitLabel());
        self::assertSame('Thanks!', $form->getSuccessMessage());
        self::assertFalse($form->isEnabled());
        self::assertSame(42, $form->getSubmissionCount());
        self::assertSame('tr', $form->getLanguage());
        self::assertSame(5, $form->getTranslationOf());
    }

    public function testFromRow(): void
    {
        $form = Form::fromRow([
            'id'               => '3',
            'name'             => 'Survey',
            'slug'             => 'survey',
            'fields'           => '[{"type":"textarea"}]',
            'settings'         => '{"ajax":true}',
            'notify_email'     => 'admin@test.com',
            'submit_label'     => 'Go',
            'success_message'  => 'Done',
            'enabled'          => '0',
            'submission_count' => '100',
            'language'         => 'tr',
            'translation_of'   => '1',
            'created_at'       => '2024-01-01 00:00:00',
            'updated_at'       => '2024-06-01 00:00:00',
        ]);

        self::assertSame(3, $form->getId());
        self::assertSame('Survey', $form->getName());
        self::assertSame([['type' => 'textarea']], $form->getFields());
        self::assertSame(['ajax' => true], $form->getSettings());
        self::assertSame('admin@test.com', $form->getNotifyEmail());
        self::assertSame('Go', $form->getSubmitLabel());
        self::assertSame('Done', $form->getSuccessMessage());
        self::assertFalse($form->isEnabled());
        self::assertSame(100, $form->getSubmissionCount());
        self::assertSame('tr', $form->getLanguage());
        self::assertSame(1, $form->getTranslationOf());
        self::assertInstanceOf(\DateTimeImmutable::class, $form->getCreatedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $form->getUpdatedAt());
    }

    public function testFromRowNullables(): void
    {
        $form = Form::fromRow([
            'id'   => '1',
            'name' => 'X',
            'slug' => 'x',
        ]);

        self::assertSame([], $form->getFields());
        self::assertNull($form->getNotifyEmail());
        self::assertNull($form->getSuccessMessage());
        self::assertNull($form->getTranslationOf());
    }

    public function testFieldWithoutConditionsIsAlwaysVisible(): void
    {
        $form = new Form('Contact', new Slug('contact'));

        self::assertTrue($form->isFieldVisible(['type' => 'text', 'name' => 'company'], []));
        self::assertTrue($form->isFieldVisible(
            ['type' => 'text', 'name' => 'company', 'conditions' => ['logic' => 'all', 'rules' => []]],
            []
        ));
    }

    public function testEqualsConditionFollowsChoiceAnswer(): void
    {
        $form  = new Form('Contact', new Slug('contact'));
        $field = [
            'type'       => 'text',
            'name'       => 'company_name',
            'conditions' => [
                'logic' => 'all',
                'rules' => [['field' => 'request_type', 'operator' => 'equals', 'value' => 'company']],
            ],
        ];

        self::assertFalse($form->isFieldVisible($field, []));
        self::assertFalse($form->isFieldVisible($field, ['request_type' => 'person']));
        self::assertTrue($form->isFieldVisible($field, ['request_type' => 'company']));
    }

    public function testAnyLogicAndCheckboxOperators(): void
    {
        $form  = new Form('Contact', new Slug('contact'));
        $field = [
            'type'       => 'text',
            'name'       => 'details',
            'conditions' => [
                'logic' => 'any',
                'rules' => [
                    ['field' => 'agree', 'operator' => 'checked', 'value' => ''],
                    ['field' => 'topics', 'operator' => 'contains', 'value' => 'billing'],
                ],
            ],
        ];

        self::assertFalse($form->isFieldVisible($field, []));
        self::assertTrue($form->isFieldVisible($field, ['agree' => '1']));
        self::assertTrue($form->isFieldVisible($field, ['topics' => ['billing', 'sales']]));
        self::assertFalse($form->isFieldVisible($field, ['topics' => ['sales']]));
    }

    public function testAllLogicRequiresEveryRule(): void
    {
        $form  = new Form('Contact', new Slug('contact'));
        $field = [
            'type'       => 'text',
            'name'       => 'details',
            'conditions' => [
                'logic' => 'all',
                'rules' => [
                    ['field' => 'region', 'operator' => 'not_equals', 'value' => 'eu'],
                    ['field' => 'topics', 'operator' => 'not_contains', 'value' => 'legal'],
                ],
            ],
        ];

        self::assertTrue($form->isFieldVisible($field, ['region' => 'us']));
        self::assertFalse($form->isFieldVisible($field, ['region' => 'eu']));
        self::assertFalse($form->isFieldVisible($field, ['region' => 'us', 'topics' => ['legal']]));
    }
}
