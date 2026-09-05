<?php

declare(strict_types=1);

use Capell\Core\Actions\Properties\SyncBuiltInPropertySetsAction;
use Capell\Core\Actions\Upgrade\RunCapellUpgradeAction;
use Illuminate\Database\Migrations\Migration;

/**
 * Data migration: seed/refresh Core's built-in property sets. Migrations are
 * Core's existing install-and-upgrade seed pipeline
 * ({@see RunCapellUpgradeAction} runs
 * `RunDatabaseMigrationsAction` on every upgrade, and a fresh install runs the
 * full schema too), so this runs exactly once per environment — a future
 * built-in-set change ships as a new migration calling the sync action again,
 * the same way any other evolving seed data would.
 */
return new class extends Migration
{
    public function up(): void
    {
        SyncBuiltInPropertySetsAction::run();
    }

    public function down(): void
    {
        // Built-in property sets are not removed on rollback — values may
        // already reference them, and the lifecycle rule (see
        // SyncBuiltInPropertySetsAction) is additive-only by design.
    }
};
