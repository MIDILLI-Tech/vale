<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use ValeApp\Domain\Membership\Entity\MembershipPlan;
use ValeApp\Domain\Membership\Repository\PlanRepositoryInterface;

final class DbalMembershipPlanRepository implements PlanRepositoryInterface
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    public function findById(int $id): ?MembershipPlan
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'membership_plans WHERE id = ?',
            [$id],
            [ParameterType::INTEGER],
        );
        return $row !== false ? MembershipPlan::fromRow($row) : null;
    }

    /** @inheritDoc */
    public function findAll(): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'membership_plans ORDER BY sort_order ASC, name ASC',
        );
        return array_map(fn (array $row) => MembershipPlan::fromRow($row), $rows);
    }

    public function findAllActive(): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'membership_plans WHERE is_active = TRUE ORDER BY sort_order ASC, name ASC',
        );
        return array_map(fn (array $row) => MembershipPlan::fromRow($row), $rows);
    }

    public function save(MembershipPlan $plan): MembershipPlan
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $data = [
            'name'          => $plan->getName(),
            'slug'          => $plan->getSlug(),
            'description'   => $plan->getDescription(),
            'price'         => $plan->getPrice(),
            'plan_interval' => $plan->getInterval(),
            'gateway'       => $plan->getGateway(),
            'tag'           => $plan->getTag(),
            'trial_days'    => $plan->getTrialDays(),
            'trial_tag'     => $plan->getTrialTag(),
            'trial_price'   => $plan->getTrialPrice(),
            'sort_order'    => $plan->getSortOrder(),
            'is_active'     => $plan->isActive() ? 1 : 0,
        ];

        if ($plan->isNew()) {
            $data['created_at'] = $now;
            $this->db->insert(VL_DB_PREFIX . 'membership_plans', $data);
            $plan->setId((int) $this->db->lastInsertId());
        } else {
            $data['updated_at'] = $now;
            $this->db->update(VL_DB_PREFIX . 'membership_plans', $data, ['id' => $plan->getId()]);
        }

        return $plan;
    }

    public function delete(int $id): void
    {
        $this->db->delete(VL_DB_PREFIX . 'membership_plans', ['id' => $id]);
    }
}
