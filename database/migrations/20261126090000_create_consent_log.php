<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateConsentLog extends AbstractMigration
{
    public function change(): void
    {
        $p = $this->getAdapter()->getOption('db_prefix') ?? 'vl_';

        $this->table($p . 'consent_log', ['id' => true, 'signed' => false])
            ->addColumn('consent_id', 'string', ['limit' => 64])
            ->addColumn('action', 'string', ['limit' => 20])
            ->addColumn('categories', 'text', ['null' => true])
            ->addColumn('policy_version', 'string', ['limit' => 20, 'default' => '1'])
            ->addColumn('user_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['consent_id'], ['name' => 'idx_consent_log_consent'])
            ->addIndex(['created_at'], ['name' => 'idx_consent_log_created'])
            ->create();
    }
}
