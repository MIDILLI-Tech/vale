<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddDeliveryDateSourceAndDeadlineEstimate extends AbstractMigration
{
    public function change(): void
    {
        $p = $this->getAdapter()->getOption('db_prefix') ?? 'vl_';

        $this->table($p . 'orders')
            ->addColumn('delivered_at_source', 'string', [
                'limit'   => 16,
                'null'    => true,
                'default' => null,
                'after'   => 'delivered_at',
            ])
            ->update();

        $this->table($p . 'withdrawal_requests')
            ->addColumn('deadline_estimated', 'boolean', [
                'default' => false,
                'null'    => false,
                'after'   => 'deadline_at',
            ])
            ->update();

        // Every delivery date that existed before this migration was entered
        // by a human, so it counts as confirmed.
        $this->execute(
            'UPDATE ' . $p . "orders SET delivered_at_source = 'manual' WHERE delivered_at IS NOT NULL"
        );
    }
}
