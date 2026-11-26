<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddProductComplianceFields extends AbstractMigration
{
    public function change(): void
    {
        $p = $this->getAdapter()->getOption('db_prefix') ?? 'vl_';

        $this->table($p . 'products')
            ->addColumn('unit', 'string', ['limit' => 16, 'null' => true, 'default' => null])
            ->addColumn('base_amount', 'decimal', ['precision' => 12, 'scale' => 3, 'null' => true, 'default' => null])
            ->addColumn('manufacturer_name', 'string', ['limit' => 191, 'null' => true, 'default' => null])
            ->addColumn('manufacturer_address', 'text', ['null' => true, 'default' => null])
            ->addColumn('eu_responsible_name', 'string', ['limit' => 191, 'null' => true, 'default' => null])
            ->addColumn('eu_responsible_address', 'text', ['null' => true, 'default' => null])
            ->addColumn('country_of_origin', 'string', ['limit' => 2, 'null' => true, 'default' => null])
            ->addColumn('safety_warnings', 'text', ['null' => true, 'default' => null])
            ->addColumn('manual_url', 'string', ['limit' => 500, 'null' => true, 'default' => null])
            ->addColumn('manual_label', 'string', ['limit' => 191, 'null' => true, 'default' => null])
            ->addColumn('digital_waiver', 'boolean', ['default' => false])
            ->addColumn('manual_file_ids', 'text', ['null' => true, 'default' => null])
            ->update();
    }
}
