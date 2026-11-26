<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddOrderWithdrawalWaiver extends AbstractMigration
{
    public function change(): void
    {
        $p = $this->getAdapter()->getOption('db_prefix') ?? 'vl_';

        $this->table($p . 'orders')
            ->addColumn('withdrawal_waiver_accepted_at', 'datetime', ['null' => true, 'default' => null])
            ->update();
    }
}
