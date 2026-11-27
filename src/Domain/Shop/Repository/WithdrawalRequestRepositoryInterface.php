<?php

declare(strict_types=1);

namespace ValeApp\Domain\Shop\Repository;

use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\Shop\Entity\WithdrawalRequest;

interface WithdrawalRequestRepositoryInterface
{
    public function findById(int $id): ?WithdrawalRequest;

    public function findByReference(string $reference): ?WithdrawalRequest;

    public function findOpenByOrderAndEmail(string $orderNumber, string $email): ?WithdrawalRequest;

    /** @return WithdrawalRequest[] */
    public function findAll(Pagination $pagination, array $filters = []): array;

    public function count(array $filters = []): int;

    /** @return array<string, int> */
    public function countByStatus(): array;

    public function save(WithdrawalRequest $request): WithdrawalRequest;
}
