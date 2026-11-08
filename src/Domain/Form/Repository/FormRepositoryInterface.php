<?php

declare(strict_types=1);

namespace ValeApp\Domain\Form\Repository;

use ValeApp\Domain\Form\Entity\Form;
use ValeApp\Domain\Form\Entity\FormSubmission;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\Shared\ValueObject\Slug;

interface FormRepositoryInterface
{
    public function findById(int $id): ?Form;

    public function findBySlug(Slug $slug): ?Form;

    /** @return Form[] */
    public function findAll(?string $language = null): array;

    public function save(Form $form): Form;

    public function delete(int $id): void;

    public function saveSubmission(FormSubmission $submission): FormSubmission;

    /**
     * Check if a submission for the form already contains the given email value.
     */
    public function hasSubmissionWithEmail(int $formId, string $email): bool;

    /** @return FormSubmission[] */
    public function findSubmissions(int $formId, Pagination $pagination): array;

    /** @return FormSubmission[] */
    public function findAllSubmissions(int $formId): array;

    public function countSubmissions(int $formId): int;

    public function markSubmissionRead(int $submissionId): void;

    public function deleteSubmission(int $submissionId): void;

    /**
     * Mark all submissions for a form as read.
     */
    public function markSubmissionsAsRead(int $formId): void;

    /**
     * Find translations for a form.
     * @return Form[]
     */
    public function findTranslations(int $originalId): array;

    /**
     * Find potential original forms to link translation to.
     * @return Form[]
     */
    public function findPotentialOriginals(?int $excludeId = null, ?string $language = null): array;

    /**
     * Find all submissions for multiple forms.
     * @return FormSubmission[]
     */
    public function findAllSubmissionsForForms(array $formIds): array;

    /**
     * Mark all submissions for multiple forms as read.
     */
    public function markSubmissionsAsReadForForms(array $formIds): void;
}
