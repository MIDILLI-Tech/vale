<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddProductWarranty extends AbstractMigration
{
    public function change(): void
    {
        $p = $this->getAdapter()->getOption('db_prefix') ?? 'vl_';

        $this->table($p . 'products')
            ->addColumn('warranty_months', 'integer', ['null' => true, 'default' => null, 'signed' => false])
            ->update();

        $this->table($p . 'product_variations')
            ->addColumn('warranty_months', 'integer', ['null' => true, 'default' => null, 'signed' => false])
            ->update();

        $this->table($p . 'order_items')
            ->addColumn('warranty_months', 'integer', ['null' => true, 'default' => null, 'signed' => false])
            ->update();
    }
}
