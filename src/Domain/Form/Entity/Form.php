<?php

declare(strict_types=1);

namespace ValeApp\Domain\Form\Entity;

use ValeApp\Domain\Shared\Entity\BaseEntity;
use ValeApp\Domain\Shared\ValueObject\Slug;

class Form extends BaseEntity
{
    private string $name;
    private Slug $slug;
    private array $fields                    = [];
    private array $settings                  = [];
    private ?string $notifyEmail             = null;
    private string $submitLabel              = 'Submit';
    private ?string $successMessage          = null;
    private bool $enabled                    = true;
    private int $submissionCount             = 0;
    private string $language                 = 'en';
    private ?int $translationOf              = null;
    private bool $translatedInTargetLanguage = false;
    private array $translatedLanguages       = [];

    public function __construct(string $name, Slug $slug)
    {
        $this->name = $name;
        $this->slug = $slug;
    }

    public function getName(): string
    {
        return $this->name;
    }
    public function getSlug(): Slug
    {
        return $this->slug;
    }
    /** @return array<int, array<string,mixed>> */
    public function getFields(): array
    {
        return $this->fields;
    }
    public function getSettings(): array
    {
        return $this->settings;
    }
    public function getNotifyEmail(): ?string
    {
        return $this->notifyEmail;
    }
    public function getSubmitLabel(): string
    {
        return $this->submitLabel;
    }
    public function getSuccessMessage(): ?string
    {
        return $this->successMessage;
    }
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
    public function getSubmissionCount(): int
    {
        return $this->submissionCount;
    }
    public function isTranslatedInTargetLanguage(): bool
    {
        return $this->translatedInTargetLanguage;
    }
    public function getLanguage(): string
    {
        return $this->language;
    }
    public function getTranslationOf(): ?int
    {
        return $this->translationOf;
    }

    /**
     * Captcha type resolved from this form's settings — the single source of
     * truth shared by the standalone form page, the embedded shortcode form
     * and the submit-time validation. An explicit captcha_type wins, the
     * legacy captcha flag falls back to the math question.
     */
    public function getCaptchaType(): string
    {
        if (!empty($this->settings['captcha_type']) && $this->settings['captcha_type'] !== 'none') {
            return (string) $this->settings['captcha_type'];
        }

        if (!empty($this->settings['captcha'])) {
            return 'math';
        }

        return 'none';
    }

    public function getHoneypotFieldName(): string
    {
        $name = (string) ($this->settings['honeypot_field_name'] ?? '');

        return $name === '' ? '_hp_name' : $name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }
    public function setTranslatedInTargetLanguage(bool $flag): void
    {
        $this->translatedInTargetLanguage = $flag;
    }

    /** @return string[] */
    public function getTranslatedLanguages(): array
    {
        return $this->translatedLanguages;
    }

    /** @param string[] $languages */
    public function setTranslatedLanguages(array $languages): void
    {
        $this->translatedLanguages = array_values(array_filter(array_map('strval', $languages)));
    }

    public function setLanguage(string $language): void
    {
        $this->language = $language;
    }
    public function setTranslationOf(?int $translationOf): void
    {
        $this->translationOf = $translationOf;
    }
    public function setSlug(Slug $slug): void
    {
        $this->slug = $slug;
    }
    /** @param array<int, array<string,mixed>> $fields */
    public function setFields(array $fields): void
    {
        $this->fields = $fields;
    }
    public function setSettings(array $settings): void
    {
        $this->settings = $settings;
    }
    public function setNotifyEmail(?string $email): void
    {
        $this->notifyEmail = $email;
    }
    public function setSubmitLabel(string $label): void
    {
        $this->submitLabel = $label;
    }
    public function setSuccessMessage(?string $msg): void
    {
        $this->successMessage = $msg;
    }
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }
    public function setSubmissionCount(int $count): void
    {
        $this->submissionCount = $count;
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $form                  = new self($row['name'], new Slug($row['slug']));
        $form->id              = (int) $row['id'];
        $form->fields          = !empty($row['fields']) ? (json_decode($row['fields'], true) ?? []) : [];
        $form->settings        = !empty($row['settings']) ? (json_decode($row['settings'], true) ?? []) : [];
        $form->notifyEmail     = $row['notify_email']    ?? null;
        $form->submitLabel     = $row['submit_label']    ?? 'Submit';
        $form->successMessage  = $row['success_message'] ?? null;
        $form->enabled         = (bool) ($row['enabled'] ?? true);
        $form->submissionCount = (int) ($row['submission_count'] ?? 0);
        $form->language        = $row['language'] ?? 'en';
        $form->translationOf   = isset($row['translation_of']) && $row['translation_of'] !== '' && $row['translation_of'] !== null ? (int) $row['translation_of'] : null;
        if (!empty($row['created_at'])) {
            $form->createdAt = new \DateTimeImmutable($row['created_at']);
        }
        if (!empty($row['updated_at'])) {
            $form->updatedAt = new \DateTimeImmutable($row['updated_at']);
        }
        if (isset($row['is_translated_in_target'])) {
            $form->setTranslatedInTargetLanguage((bool) $row['is_translated_in_target']);
        }
        if (isset($row['translated_languages'])) {
            $form->setTranslatedLanguages(
                $row['translated_languages'] === null || $row['translated_languages'] === ''
                    ? []
                    : explode(',', (string) $row['translated_languages'])
            );
        }
        return $form;
    }
}
