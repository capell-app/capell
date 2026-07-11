# Authoring an upgrade step

![Capell Authoring an upgrade step screenshot](./images/screenshots/core-page-structure.png)

Use an upgrade step for one-time operations that run at deploy time:

- Backfilling new columns on existing rows
- Rewriting JSON blobs to a new schema
- Re-indexing cache keys
- Rotating cryptographic material

**Not for schema changes** — use a normal Laravel migration registered via `HasMigrations`.

## Minimal step

```php
<?php

declare(strict_types=1);

namespace Acme\MyPackage\Upgrade;

use Acme\MyPackage\Models\Widget;
use Capell\Core\Data\UpgradeContext;
use Capell\Core\Support\Upgrade\AbstractUpgradeStep;

class BackfillWidgetSchemaV2 extends AbstractUpgradeStep
{
    public function id(): string
    {
        // Stable forever. Never change. Never reuse.
        return 'acme.backfill-widget-schema-v2';
    }

    public function label(): string
    {
        return 'Backfill Acme widget schema v2';
    }

    public function package(): string
    {
        return 'acme/my-package';
    }

    public function run(UpgradeContext $context): bool
    {
        Widget::query()
            ->whereNull('schema_version')
            ->each(function ($widget): void {
                $widget->update(['schema_version' => 2]);
            });

        return true;
    }
}
```

## Version gating

```php
public function shouldRun(UpgradeContext $context): bool
{
    $current = $context->composerVersion('acme/my-package') ?? '0.0.0';

    return $context->compareVersions($current, '2.0.0') >= 0;
}
```

## Dependencies

```php
public function dependsOn(): array
{
    return ['core.create-widget-schema-version-column'];
}
```

Steps with unsatisfied dependencies are skipped with a clear reason in the log.

## Reversible steps

```php
public function rollback(UpgradeContext $context): bool
{
    Widget::query()
        ->where('schema_version', 2)
        ->each(function ($widget): void {
            $widget->update(['schema_version' => null]);
        });

    return true;
}
```

Invoke: `php artisan capell:rollback --step=acme.backfill-widget-schema-v2`.

## Register the step

In your package service provider `register()`:

```php
$this->app->tag([
    \Acme\MyPackage\Upgrade\BackfillWidgetSchemaV2::class,
], 'capell.upgrade-steps');
```

## Operation tracking

Capell records upgrade operations in `capell_upgrade_runs` and
`capell_upgrade_run_events` when those tables are available. The existing
`capell_upgrade_log` table remains the step and version ledger; do not use it
for queue state, failure messages, or output.

Admin-triggered upgrades are queue-first. If the server uses the `sync` queue
driver, lacks the operation tables, lacks the database queue table, cannot
acquire cache locks, cannot open the migration lock path, cannot connect to the
database, or has an unavailable legacy package upgrade command, Capell records a
manual-required run when possible and shows the exact server command to execute:

```bash
php artisan capell:upgrade --force --no-clear-cache --dry-run
php artisan capell:upgrade --force --no-clear-cache
```

Package `UpgradeStepContract` classes are preferred for lifecycle work. Legacy
manifest `commands.upgrade` entries remain supported for compatibility, but the
upgrade pipeline records readiness warnings and operation events so packages can
migrate to tagged upgrade steps.

## Rules

- **Stable id**: never change `id()`; never reuse.
- **Idempotent body**: `run()` must be safe to retry — a failed partial run will be retried next upgrade.
- **DB work only inside the transaction**: `run()` executes inside `DB::transaction()`. Don't do HTTP calls, queue dispatches that need a worker, or file-system work that can't be rolled back by a transaction abort.
- **Priority discipline**: 0–99 = early, 100 = default, 200+ = late.
- **Rollback is optional**: default returns `false`. Only override if your step is genuinely reversible.

## Testing

```php
it('backfills missing schema_version', function (): void {
    Widget::factory()->createOne(['schema_version' => null]);
    $context = new UpgradeContext([], [], [], false);

    $result = (new BackfillWidgetSchemaV2())->run($context);

    expect($result)->toBeTrue()
        ->and(Widget::where('schema_version', 2)->count())->toBe(1);
});
```
