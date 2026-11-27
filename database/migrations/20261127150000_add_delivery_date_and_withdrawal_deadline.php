<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddDeliveryDateAndWithdrawalDeadline extends AbstractMigration
{
    public function change(): void
    {
        $p = $this->getAdapter()->getOption('db_prefix') ?? 'vl_';

        $this->table($p . 'orders')
            ->addColumn('delivered_at', 'datetime', ['null' => true, 'default' => null, 'after' => 'paid_at'])
            ->update();

        $this->table($p . 'withdrawal_requests')
            ->addColumn('deadline_at', 'datetime', ['null' => true, 'default' => null, 'after' => 'locale'])
            ->update();
    }
}
