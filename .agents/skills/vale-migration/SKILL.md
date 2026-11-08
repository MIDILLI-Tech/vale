---
name: vale-migration
description: "Creates a Phinx database migration for Vale by asking what it should do (new table / alter columns / data migration), which tables are affected, then generating database/migrations/<timestamp>_<snake_name>.php using the repo conventions: db_prefix-aware table names, unsigned auto ids, CURRENT_TIMESTAMP defaults, named indexes, change() method, and strict MySQL/PostgreSQL/SQLite compatibility rules from AGENTS.md. USE FOR: 'add migration', schema change, new table, column alteration, backfill script. DO NOT USE FOR: seeding demo content (database/seeds + php vale db:seed), raw runtime queries in repositories, resetting the database (db:reset)."
license: AGPL-3.0
metadata:
  project: vale/vale
  reference: database/migrations/20260701000000_InitialSchema.php
---

# Create a Phinx Migration

Migrations live in `database/migrations/`, filename format `YYYYMMDDHHMMSS_snake_case_name.php`. Run with `composer migrate` (= `php vale migrate`; supports `--dry-run`). Config comes from `phinx.php` which reads `.env` (`DB_DRIVER` mysql|sqlite|pgsql, `DB_PREFIX` → adapter option `db_prefix`).

## Phase 1 — Ask (required before writing any code)

1. Purpose: new table / add or alter columns / create index / pure data migration?
2. Affected tables (existing `vl_*` names without prefix) and exact columns/types?
3. Must it be reversible? (`change()` preferred; use `up()/down()` only when reversal needs custom logic)
4. For data migrations: batched strategy? (large tables must not be updated in one statement)

## Phase 2 — Generate

Filename: current UTC timestamp + snake name, e.g. `20260826120000_add_coupon_usage_log.php`.

### Skeleton — new table

```php
<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddCouponUsageLog extends AbstractMigration
{
    public function change(): void
    {
        $p = $this->getAdapter()->getOption('db_prefix') ?? 'vl_';

        $this->table($p . 'coupon_usage_log', ['id' => true, 'signed' => false])
            ->addColumn('coupon_id', 'biginteger', ['signed' => false])
            ->addColumn('order_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['coupon_id'], ['name' => 'idx_coupon_usage_log_coupon_id'])
            ->addIndex(['order_id'], ['name' => 'idx_coupon_usage_log_order_id'])
            ->create();
    }
}
```

Conventions copied from the InitialSchema:
- Always resolve prefix via `$this->getAdapter()->getOption('db_prefix') ?? 'vl_'`.
- Auto id: `['id' => true, 'signed' => false]`. FK-ish integer columns: `'signed' => false` to match.
- Timestamps: `created_at` datetime with `'default' => 'CURRENT_TIMESTAMP'`, `updated_at` datetime nullable.
- Indexes get explicit names `idx_<table_without_prefix>_<col>`.

### Skeleton — alter existing table

```php
public function change(): void
{
    $p = $this->getAdapter()->getOption('db_prefix') ?? 'vl_';

    $this->table($p . 'orders')
        ->addColumn('fulfillment_note', 'text', ['null' => true, 'after' => 'notes'])
        ->update();
}
```

(`after` is ignored on SQLite/PostgreSQL by Phinx — safe to keep.)

### Data migration skeleton (reversible-safe)

```php
public function up(): void
{
    $p = $this->getAdapter()->getOption('db_prefix') ?? 'vl_';

    // Batched to keep memory/lock footprint low; works on all three engines
    $this->execute(
        "UPDATE {$p}settings SET setting_value = '1'"
        . " WHERE setting_key = 'example_flag' AND (setting_value IS NULL OR setting_value = '')"
    );
}

public function down(): void
{
    $p = $this->getAdapter()->getOption('db_prefix') ?? 'vl_';
    $this->execute("UPDATE {$p}settings SET setting_value = '0' WHERE setting_key = 'example_flag'");
}
```

## Cross-DB compatibility rules (AGENTS.md §14 — mandatory)

All SQL must run on **MySQL 8.4+, MariaDB 11.8+, PostgreSQL 18+ and SQLite**:

- Use `CONCAT(a, b)` — never `||` (that's OR in MySQL)
- Use `COALESCE(col, default)` for fallbacks
- Use `LOWER()`/`UPPER()` — not `LCASE()`/`UCASE()`
- No engine-specific DDL (`ENGINE=`, `AUTO_INCREMENT`, backtick-quoted identifiers in raw SQL — prefer Phinx Table API which abstracts all three)
- Avoid `GROUP BY` on non-aggregated columns
- Careful with `NOT IN (SELECT ...)` vs NULL semantics across engines
- Prefer Phinx's query/Table API over raw `$this->execute()` whenever possible

## Phase 3 — Verify

Ask the user which driver(s) apply, then per AGENTS.md test with both before committing if the change touches SQL used at runtime:

```bash
php vale migrate --dry-run     # preview first, always
php vale migrate               # apply (MySQL .env)
# then switch DB_DRIVER=sqlite (+ path) in .env and re-test on a copy
php vale rollback              # verify reversibility of down()/change()
```

Also confirm the app boots after migrating (schema matches entity/repository expectations — e.g. new columns need matching entity fields or they stay unused).

## Checklist

- [ ] Filename `YYYYMMDDHHMMSS_snake_case.php`, class name matches camel-cased filename
- [ ] Prefix resolved via adapter option, never hardcoded `vl_`
- [ ] Unsigned ids/columns consistent with InitialSchema style
- [ ] Named indexes `idx_*`
- [ ] Raw SQL obeys cross-engine rules
- [ ] `--dry-run` clean; rollback verified
- [ ] Docs reminder: schema-affecting changes may warrant updates in vale-docs developer/architecture docs
