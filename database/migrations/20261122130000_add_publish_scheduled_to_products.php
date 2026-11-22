<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddPublishScheduledToProducts extends AbstractMigration
{
    public function change(): void
    {
        $p = $this->getAdapter()->getOption('db_prefix') ?? 'vl_';

        $this->table($p . 'products')
            ->addColumn('publish_scheduled', 'boolean', ['default' => false])
            ->addIndex(
                ['status', 'publish_scheduled', 'published_at'],
                ['name' => 'idx_products_go_public'],
            )
            ->update();
    }
}
