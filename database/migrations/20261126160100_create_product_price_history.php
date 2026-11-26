<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateProductPriceHistory extends AbstractMigration
{
    public function change(): void
    {
        $p = $this->getAdapter()->getOption('db_prefix') ?? 'vl_';

        $this->table($p . 'product_price_history')
            ->addColumn('product_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('price', 'decimal', ['precision' => 12, 'scale' => 2, 'null' => false])
            ->addColumn('recorded_at', 'datetime', ['null' => false])
            ->addIndex(['product_id', 'recorded_at'], ['name' => 'idx_price_history_product'])
            ->create();
    }
}
