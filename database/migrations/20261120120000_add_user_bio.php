<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddUserBio extends AbstractMigration
{
    public function change(): void
    {
        $p = $this->getAdapter()->getOption('db_prefix') ?? 'vl_';

        $this->table($p . 'users')
            ->addColumn('bio', 'text', ['null' => true])
            ->addColumn('display_name', 'string', ['limit' => 255, 'null' => true])
            ->update();
    }
}
