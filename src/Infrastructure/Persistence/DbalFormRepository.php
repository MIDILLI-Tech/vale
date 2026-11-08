<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Persistence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use ValeApp\Domain\Form\Entity\Form;
use ValeApp\Domain\Form\Entity\FormSubmission;
use ValeApp\Domain\Form\Repository\FormRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\Shared\ValueObject\Slug;

final class DbalFormRepository implements FormRepositoryInterface
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function findById(int $id): ?Form
    {
        $row = $this->db->fetchAssociative('SELECT * FROM ' . VL_DB_PREFIX . 'forms' . ' WHERE id = ?', [$id], [ParameterType::INTEGER]);
        return $row !== false ? Form::fromRow($row) : null;
    }

    public function findBySlug(Slug $slug): ?Form
    {
        $row = $this->db->fetchAssociative('SELECT * FROM ' . VL_DB_PREFIX . 'forms' . ' WHERE slug = ?', [(string) $slug], [ParameterType::STRING]);
        return $row !== false ? Form::fromRow($row) : null;
    }

    /** @return Form[] */
    public function findAll(?string $language = null): array
    {
        $sql = 'SELECT f.*, COALESCE(sc.cnt, 0) AS submission_count FROM ' . VL_DB_PREFIX . 'forms' . ' f '
            . 'LEFT JOIN (SELECT form_id, COUNT(*) AS cnt FROM ' . VL_DB_PREFIX . 'form_submissions' . ' GROUP BY form_id) sc '
            . 'ON sc.form_id = f.id';
        $params = [];
        $types  = [];

        if ($language !== null) {
            $sql .= ' WHERE f.language = ?';
            $params[] = $language;
            $types[]  = ParameterType::STRING;
        }

        $sql .= ' ORDER BY f.id ASC';

        $rows = $this->db->fetchAllAssociative($sql, $params, $types);
        return array_map(fn (array $row) => Form::fromRow($row), $rows);
    }

    public function save(Form $form): Form
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $data = [
            'name'            => $form->getName(),
            'slug'            => (string) $form->getSlug(),
            'fields'          => json_encode($form->getFields()),
            'settings'        => json_encode($form->getSettings()),
            'notify_email'    => $form->getNotifyEmail(),
            'submit_label'    => $form->getSubmitLabel(),
            'success_message' => $form->getSuccessMessage(),
            'enabled'         => $form->isEnabled() ? 1 : 0,
            'language'        => $form->getLanguage(),
            'translation_of'  => $form->getTranslationOf(),
            'updated_at'      => $now,
        ];

        if ($form->getId() === null) {
            $data['created_at'] = $now;
            $this->db->insert(VL_DB_PREFIX . 'forms', $data);
            $form->setId((int) $this->db->lastInsertId());
        } else {
            $this->db->update(VL_DB_PREFIX . 'forms', $data, ['id' => $form->getId()]);
        }

        return $form;
    }

    public function delete(int $id): void
    {
        $this->db->delete(VL_DB_PREFIX . 'forms', ['id' => $id]);
    }

    public function saveSubmission(FormSubmission $submission): FormSubmission
    {
        $data = [
            'form_id'         => $submission->getFormId(),
            'data'            => json_encode($submission->getData()),
            'submitter_email' => $this->normalizeSubmitterEmail($submission->getData()['email'] ?? null),
            'ip_address'      => $submission->getIpAddress(),
            'user_agent'      => $submission->getUserAgent(),
            'is_read'         => $submission->isRead() ? 1 : 0,
            'created_at'      => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ];
        $this->db->insert(VL_DB_PREFIX . 'form_submissions', $data);
        $submission->setId((int) $this->db->lastInsertId());
        return $submission;
    }

    public function hasSubmissionWithEmail(int $formId, string $email): bool
    {
        $submitterEmail = $this->normalizeSubmitterEmail($email);
        if ($submitterEmail === null) {
            return false;
        }
        return $this->db->fetchOne(
            'SELECT 1 FROM ' . VL_DB_PREFIX . 'form_submissions'
            . ' WHERE form_id = ? AND submitter_email = ?',
            [$formId, $submitterEmail],
            [ParameterType::INTEGER, ParameterType::STRING]
        ) !== false;
    }

    private function normalizeSubmitterEmail(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $email = mb_strtolower(trim($value));
        return $email !== '' ? $email : null;
    }

    /** @return FormSubmission[] */
    public function findAllSubmissions(int $formId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'form_submissions' . ' WHERE form_id = ? ORDER BY id DESC',
            [$formId],
            [ParameterType::INTEGER],
        );
        return array_map(fn (array $row) => FormSubmission::fromRow($row), $rows);
    }

    /** @return FormSubmission[] */
    public function findSubmissions(int $formId, Pagination $pagination): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'form_submissions' . ' WHERE form_id = ? ORDER BY id DESC LIMIT ? OFFSET ?',
            [$formId, $pagination->perPage, $pagination->offset],
            [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER],
        );
        return array_map(fn (array $row) => FormSubmission::fromRow($row), $rows);
    }

    public function countSubmissions(int $formId): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'form_submissions' . ' WHERE form_id = ?', [$formId], [ParameterType::INTEGER]);
    }

    public function markSubmissionRead(int $submissionId): void
    {
        $this->db->update(VL_DB_PREFIX . 'form_submissions', ['is_read' => 1], ['id' => $submissionId]);
    }

    public function deleteSubmission(int $submissionId): void
    {
        $this->db->delete(VL_DB_PREFIX . 'form_submissions', ['id' => $submissionId]);
    }

    public function markSubmissionsAsRead(int $formId): void
    {
        $this->db->update(
            VL_DB_PREFIX . 'form_submissions',
            ['is_read' => 1],
            ['form_id' => $formId, 'is_read' => 0]
        );
    }

    /** @return Form[] */
    public function findTranslations(int $originalId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'forms' . ' WHERE id = ? OR translation_of = ? ORDER BY language ASC',
            [$originalId, $originalId],
            [ParameterType::INTEGER, ParameterType::INTEGER]
        );
        return array_map(fn (array $row) => Form::fromRow($row), $rows);
    }

    /** @return Form[] */
    public function findPotentialOriginals(?int $excludeId = null, ?string $language = null): array
    {
        $p      = VL_DB_PREFIX;
        $select = 'SELECT f.*';
        $params = [];
        $types  = [];

        if ($language !== null) {
            $select .= ', CASE WHEN (f.language = ? OR EXISTS (SELECT 1 FROM ' . $p . 'forms t'
                . ' WHERE t.translation_of = f.id AND t.language = ?'
                . ($excludeId !== null ? ' AND t.id != ?' : '')
                . ')) THEN 1 ELSE 0 END AS is_translated_in_target';
            $params[] = $language;
            $params[] = $language;
            $types[]  = ParameterType::STRING;
            $types[]  = ParameterType::STRING;
            if ($excludeId !== null) {
                $params[] = $excludeId;
                $types[]  = ParameterType::INTEGER;
            }
        }

        $sql = $select . ' FROM ' . $p . 'forms f WHERE f.translation_of IS NULL';

        if ($excludeId !== null) {
            $sql     .= ' AND f.id != ?';
            $params[] = $excludeId;
            $types[]  = ParameterType::INTEGER;
        }

        $sql .= ' ORDER BY f.name ASC';
        $rows  = $this->db->fetchAllAssociative($sql, $params, $types);
        $forms = array_map(fn (array $row) => Form::fromRow($row), $rows);
        $this->hydrateTranslatedLanguages($forms, array_map(fn (array $r) => (int) $r['id'], $rows));
        return $forms;
    }

    /** @return FormSubmission[] */
    public function findAllSubmissionsForForms(array $formIds): array
    {
        if (empty($formIds)) {
            return [];
        }
        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'form_submissions' . ' WHERE form_id IN (?) ORDER BY id DESC',
            [$formIds],
            [ArrayParameterType::INTEGER],
        );
        return array_map(fn (array $row) => FormSubmission::fromRow($row), $rows);
    }

    public function markSubmissionsAsReadForForms(array $formIds): void
    {
        if (empty($formIds)) {
            return;
        }
        $this->db->executeStatement(
            'UPDATE ' . VL_DB_PREFIX . 'form_submissions' . ' SET is_read = TRUE WHERE form_id IN (?) AND is_read = FALSE',
            [$formIds],
            [ArrayParameterType::INTEGER]
        );
    }

    private function hydrateTranslatedLanguages(array $forms, array $originalIds): void
    {
        if (empty($originalIds)) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($originalIds), '?'));
        $types        = array_fill(0, count($originalIds), ParameterType::INTEGER);
        $rows         = $this->db->fetchAllAssociative(
            'SELECT translation_of, language FROM ' . VL_DB_PREFIX . 'forms WHERE translation_of IN (' . $placeholders . ')',
            $originalIds,
            $types
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['translation_of']][] = (string) $r['language'];
        }
        foreach ($forms as $form) {
            $form->setTranslatedLanguages($map[(int) $form->getId()] ?? []);
        }
    }
}
