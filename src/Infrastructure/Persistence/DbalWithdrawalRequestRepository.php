<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\Shop\Entity\WithdrawalRequest;
use ValeApp\Domain\Shop\Repository\WithdrawalRequestRepositoryInterface;

final class DbalWithdrawalRequestRepository implements WithdrawalRequestRepositoryInterface
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    public function findById(int $id): ?WithdrawalRequest
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'withdrawal_requests WHERE id = ?',
            [$id],
            [ParameterType::INTEGER],
        );

        return $row === false ? null : WithdrawalRequest::fromRow($row);
    }

    public function findByReference(string $reference): ?WithdrawalRequest
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'withdrawal_requests WHERE reference = ?',
            [$reference],
            [ParameterType::STRING],
        );

        return $row === false ? null : WithdrawalRequest::fromRow($row);
    }

    public function findOpenByOrderAndEmail(string $orderNumber, string $email): ?WithdrawalRequest
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'withdrawal_requests'
            . " WHERE order_number = ? AND LOWER(customer_email) = ? AND status IN ('pending', 'accepted', 'completed')"
            . ' ORDER BY id DESC',
            [$orderNumber, mb_strtolower(trim($email))],
            [ParameterType::STRING, ParameterType::STRING],
        );

        return $row === false ? null : WithdrawalRequest::fromRow($row);
    }

    /** @return WithdrawalRequest[] */
    public function findAll(Pagination $pagination, array $filters = []): array
    {
        [$where, $params, $types] = $this->buildWhere($filters);
        $orderBy                  = $pagination->orderByClause(
            ['id', 'reference', 'order_number', 'status', 'created_at'],
            'created_at DESC',
        );

        $params[] = $pagination->perPage;
        $params[] = $pagination->offset;
        $types[]  = ParameterType::INTEGER;
        $types[]  = ParameterType::INTEGER;

        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'withdrawal_requests' . $where . $orderBy . ' LIMIT ? OFFSET ?',
            $params,
            $types,
        );

        return array_map(static fn (array $row): WithdrawalRequest => WithdrawalRequest::fromRow($row), $rows);
    }

    public function count(array $filters = []): int
    {
        [$where, $params, $types] = $this->buildWhere($filters);

        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'withdrawal_requests' . $where,
            $params,
            $types,
        );
    }

    /** @return array<string, int> */
    public function countByStatus(): array
    {
        $rows   = $this->db->fetchAllAssociative(
            'SELECT status, COUNT(*) AS total FROM ' . VL_DB_PREFIX . 'withdrawal_requests GROUP BY status',
        );
        $counts = array_fill_keys(WithdrawalRequest::STATUSES, 0);

        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            if (array_key_exists($status, $counts)) {
                $counts[$status] = (int) $row['total'];
            }
        }

        return $counts;
    }

    public function save(WithdrawalRequest $request): WithdrawalRequest
    {
        $data = [
            'order_id'       => $request->getOrderId(),
            'order_number'   => $request->getOrderNumber(),
            'customer_name'  => $request->getCustomerName(),
            'customer_email' => $request->getCustomerEmail(),
            'items'          => $request->getItems() === []
                ? null
                : (string) json_encode($request->getItems(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'items_note'     => $request->getItemsNote(),
            'reason'         => $request->getReason(),
            'status'         => $request->getStatus(),
            'reference'      => $request->getReference(),
            'locale'         => $request->getLocale(),
            'source'         => $request->getSource(),
            'admin_note'     => $request->getAdminNote(),
            'ip_address'     => $request->getIpAddress(),
            'deadline_at'    => $request->getDeadlineAt()?->format('Y-m-d H:i:s'),
            'processed_at'   => $request->getProcessedAt()?->format('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ];

        if ($request->isNew()) {
            $data['created_at'] = date('Y-m-d H:i:s');
            $this->db->insert(VL_DB_PREFIX . 'withdrawal_requests', $data);
            $request->setId((int) $this->db->lastInsertId());

            return $request;
        }

        $this->db->update(VL_DB_PREFIX . 'withdrawal_requests', $data, ['id' => (int) $request->getId()]);

        return $request;
    }

    /**
     * @return array{0: string, 1: list<mixed>, 2: list<int>}
     */
    private function buildWhere(array $filters): array
    {
        $conditions = [];
        $params     = [];
        $types      = [];

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && in_array($status, WithdrawalRequest::STATUSES, true)) {
            $conditions[] = 'status = ?';
            $params[]     = $status;
            $types[]      = ParameterType::STRING;
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $conditions[] = '(reference LIKE ? OR order_number LIKE ? OR customer_email LIKE ? OR customer_name LIKE ?)';
            $like         = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like);
            array_push($types, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING);
        }

        return [
            $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions),
            $params,
            $types,
        ];
    }
}
