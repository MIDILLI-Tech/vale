<?php

declare(strict_types=1);

namespace ValeApp\Domain\Membership\Repository;

use ValeApp\Domain\Membership\Entity\MembershipPlan;

interface PlanRepositoryInterface
{
    public function findById(int $id): ?MembershipPlan;

    /** @return MembershipPlan[] */
    public function findAll(): array;

    /** @return MembershipPlan[] */
    public function findAllActive(): array;

    public function save(MembershipPlan $plan): MembershipPlan;

    public function delete(int $id): void;
}
