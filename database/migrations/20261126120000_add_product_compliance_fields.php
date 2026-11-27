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
            ->addColumn('manual_urls', 'text', ['null' => true, 'default' => null])
            ->addColumn('manual_documents', 'text', ['null' => true, 'default' => null])
            ->addColumn('digital_waiver', 'boolean', ['default' => false])
            ->addColumn('manual_file_ids', 'text', ['null' => true, 'default' => null])
            ->update();

        $this->table($p . 'orders')
            ->addColumn('withdrawal_waiver_accepted_at', 'datetime', ['null' => true, 'default' => null])
            ->update();

        $this->table($p . 'product_variations')
            ->addColumn('unit', 'string', ['limit' => 16, 'null' => true, 'default' => null])
            ->addColumn('base_amount', 'decimal', ['precision' => 12, 'scale' => 3, 'null' => true, 'default' => null])
            ->addColumn('manual_documents', 'text', ['null' => true, 'default' => null])
            ->update();

        $this->table($p . 'product_price_history')
            ->addColumn('product_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('variation_id', 'integer', ['signed' => false, 'null' => true, 'default' => null])
            ->addColumn('price', 'decimal', ['precision' => 12, 'scale' => 2, 'null' => false])
            ->addColumn('recorded_at', 'datetime', ['null' => false])
            ->addIndex(['product_id', 'recorded_at'], ['name' => 'idx_price_history_product'])
            ->addIndex(['variation_id', 'recorded_at'], ['name' => 'idx_price_history_variation'])
            ->create();

        $this->table($p . 'withdrawal_requests', ['id' => true, 'signed' => false])
            ->addColumn('order_id', 'integer', ['signed' => false, 'null' => true, 'default' => null])
            ->addColumn('order_number', 'string', ['limit' => 64])
            ->addColumn('customer_name', 'string', ['limit' => 191, 'null' => true, 'default' => null])
            ->addColumn('customer_email', 'string', ['limit' => 191])
            ->addColumn('items', 'text', ['null' => true, 'default' => null])
            ->addColumn('items_note', 'text', ['null' => true, 'default' => null])
            ->addColumn('reason', 'text', ['null' => true, 'default' => null])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'pending'])
            ->addColumn('reference', 'string', ['limit' => 32])
            ->addColumn('locale', 'string', ['limit' => 10, 'default' => 'en'])
            ->addColumn('source', 'string', ['limit' => 20, 'default' => 'online'])
            ->addColumn('admin_note', 'text', ['null' => true, 'default' => null])
            ->addColumn('ip_address', 'string', ['limit' => 64, 'null' => true, 'default' => null])
            ->addColumn('processed_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['null' => true, 'default' => null])
            ->addIndex(['reference'], ['unique' => true, 'name' => 'uniq_withdrawal_reference'])
            ->addIndex(['status'], ['name' => 'idx_withdrawal_status'])
            ->addIndex(['order_number'], ['name' => 'idx_withdrawal_order'])
            ->addIndex(['created_at'], ['name' => 'idx_withdrawal_created'])
            ->create();
    }
}
