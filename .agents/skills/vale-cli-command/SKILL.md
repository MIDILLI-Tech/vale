---
name: vale-cli-command
description: "Adds a new `php vale <command>` Symfony Console command to Vale by asking questions (command slug, purpose, state-changing vs read-only, backing service/DB/config, dry-run) and generating: app/Commands/<Name>Command.php following the repo conventions (setName/setDescription, --dry-run VALUE_NONE option for state-changing commands, SymfonyStyle output, Command::SUCCESS/FAILURE), a lazy `vale` FactoryCommandLoader registration (use import + closure arm), optional DI wiring in config/services.php, and a docs update in vale-docs/en/latest/admin/system/cli.md + AGENTS.md §21. Then verifies with php -l + --dry-run and runs a temporary dummy-data test with rollback. USE FOR: 'add CLI command', 'new php vale command', expose a task/utility as a console command. DO NOT USE FOR: migrations (vale-migration), payment gateways (vale-payment-gateway), REST API endpoints (vale-api-endpoint), webhook receivers (vale-webhook)."
license: AGPL-3.0
metadata:
  project: vale/vale
  reference: app/Commands/MediaWebpRemoveCommand.php
---

# Add a `php vale` CLI Command

CLI commands are Symfony Console `Command` classes in `app/Commands/`, registered **lazily** in the `vale` binary's `FactoryCommandLoader` so unrelated commands never force a database connection at startup. Every command uses `declare(strict_types=1)`, the `ValeApp\App\Commands` namespace, and constructor injection for its dependencies.

## Phase 1 — Ask (required before writing any code)

1. Command slug + namespace? (e.g. `media:webp-remove`) → drives `setName()`, class name, filename.
2. What does it do — **state-changing** (writes DB / deletes files / mutates config) or **read-only report** (list/doctor)?
   - State-changing → `--dry-run` is **required** (repo convention; see below).
   - Read-only → `--dry-run` not needed.
3. Backing data: which service/repository/config/docs? Constructor injection arguments:
   - `Connection` (raw DB) — pass `$connection` from `vale`.
   - A service from the DI container — pass `$container->get(Full\ClassName::class)`.
   - Config array — pass `$container->get('config.<name>')['<key>']`.
4. Does it need user-confirmation before side effects (interactive `confirm`) beyond `--dry-run`?
5. Docs: confirm `vale-docs/en/latest/admin/system/cli.md` + `AGENTS.md` §21 should be updated (default yes).

**Dry-run rule (AGENTS.md §21):** All state-changing commands support `--dry-run` for a non-destructive preview. Commands *without* it are only `key:generate`, `update:check`, `backup:list`, `doctor`. If the new command is state-changing, add `--dry-run` and guard destructive work behind it, and the `--dry-run` guard must be the **first** thing `execute()` checks.

## Phase 2 — Generate

### Step 1 — Command class: `app/Commands/<UcfirstCamel>Command.php`

Model classes on `MediaScanCommand` / `MediaWebpRemoveCommand`. Full skeleton with `--dry-run`:

```php
<?php

declare(strict_types=1);

namespace ValeApp\App\Commands;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ExampleThingCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('example:thing')
            ->setDescription('Short description of what it does')
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Only report what would change, do not apply anything',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $io->title('Title');

        // Dry-run guard FIRST for state-changing commands.
        if ($dryRun) {
            $io->warning('Dry-run mode — nothing will be changed.');
        }

        // ... do the work, branching on $dryRun ...

        $io->success('Done.');
        return Command::SUCCESS;
    }
}
```

Conventions:
- `--dry-run` is always `InputOption::VALUE_NONE` → `(bool) $input->getOption('dry-run')`.
- Use `SymfonyStyle` helpers (`title`, `section`, `text`, `writeln`, `error`, `warning`, `success`, `confirm`) — not raw `echo`/`fwrite`.
- Return `Command::SUCCESS` (0) or `Command::FAILURE` (1); wrap risky calls in `try/catch` returning `FAILURE` on error.
- Bulk-write commands (imports, migrations of content/products) bypass the normal save flow, so `post.published`/`post.updated` page-cache invalidation never fires: inject `private readonly ?PageCache $pageCache = null`, `use Concerns\FlushesPageCache`, call `$this->flushPageCache($this->pageCache, $dryRun, $io)` on non-dry-run success, and pass `$container->get(PageCache::class)` in the `vale` loader arm. Product-import commands get this free via `ProductImportCommandTrait` (just add the ctor param).
- Table/prefix constants: use the `VL_DB_PREFIX` global (defined in `vale` bootstrap) in raw SQL; never hardcode `vl_`.
- Use DBAL prepared statements / query builder for any SQL — never string-concatenate user input.

### Step 2 — Register in `vale` (lazy loader)

Add the `use` import at the top:

```php
use ValeApp\App\Commands\ExampleThingCommand;
```

Then add a factory arm inside the `FactoryCommandLoader([...])` array (~line 87+), keeping strings aligned like neighbours:

```php
'example:thing' => fn () => new ExampleThingCommand($connection, $container->get(SomeService::class)),
```

Dependency sources:
- Raw `$connection` (already built as `Connection::createFromEnv()` in `vale`).
- DI services via `$container->get(Vendor\Package\ClassName::class)` (already cached by the container).
- Config arrays via `$container->get('config.<name>')['<key>']`.

### Step 3 — DI wiring (only if a new service is needed)

If the command needs a new use case / repository / service, register it in `config/services.php` following the `Interface::class => factory(...)` pattern (AGENTS.md §17). Prefer reusing existing bindings via `$container->get(...)` over adding new ones.

### Step 4 — Docs

- Add a `### <slug>` section to `vale-docs/en/latest/admin/system/cli.md` under the matching group (only `en/latest`, never versioned snapshots per vale-docs `AGENTS.md`). Include the options table and any guard/behavior notes (mirror the `media:webp-remove` section style).
- `AGENTS.md` §21 Quick Commands Reference: add the command line under the matching group, and **add it to the `--dry-run` list** (line ~740) if state-changing.

## Phase 3 — Verify (mandatory)

1. Syntax: `php -l app/Commands/<Name>Command.php` and `php -l vale`.
2. Lint: `composer lint:changed`.
3. Smoke: run `php vale <slug> --help` to confirm it's registered and the options appear.
4. **Dry-run test:** run `php vale <slug> --dry-run` against real state — confirm it reports without changing anything.
5. **Dummy-data test with rollback (state-changing commands):**
   - Insert/pause a temporary, clearly-marked test fixture in a safe place (e.g. a throwaway DB row / a copied file / a temporarily toggled setting), run the command, confirm it processes the fixture correctly, then **restore the original state** (delete the row / delete the copied file / flip the setting back). Mirror what was done when building `media:webp-remove` (temporarily disabling `media_webp_enabled`, running `--dry-run`, then re-enabling it).
   - Any guard on the command (e.g. "runs only when X setting is off") must be toggled temporarily and restored — never left flipped.
   - Record what was created and confirm it is fully removed before finishing.
6. `composer cs` if the touched files are not already covered by `lint:changed`.

## Checklist

- [ ] `setName()` namespace:slug matches the loader key and docs heading
- [ ] `declare(strict_types=1)` + `ValeApp\App\Commands` namespace
- [ ] `--dry-run` added (state-changing only) and guarded first in `execute()`
- [ ] Bulk-write command flushes page cache on real success (`FlushesPageCache`, skipped in dry-run)
- [ ] `SymfonyStyle` used; returns `Command::SUCCESS`/`FAILURE`
- [ ] No hardcoded `vl_` prefix; DBAL prepared statements only
- [ ] Loader arm lazy (`fn () => new ...`) so unrelated commands don't force DB/DI
- [ ] `php -l` + `composer lint:changed` clean
- [ ] `--dry-run` verified non-destructive
- [ ] Dummy-data test done and **rolled back** (fixture fully removed, settings/settings untouched afterward)
- [ ] Docs: `vale-docs/en/latest/admin/system/cli.md` (en/latest only) updated
- [ ] `AGENTS.md` §21 command list + `--dry-run` list updated
- [ ] If a new skill-worthy pattern emerged, update this skill's context map

## Notes

- If a command can regress a pattern this skill (or another skill) covers, keep the matching `SKILL.md` in sync in the same change set (AGENTS.md §24).
- Read-only report commands (list/doctor-style) do not need `--dry-run`; keep `instanceof Command` small and testable.
