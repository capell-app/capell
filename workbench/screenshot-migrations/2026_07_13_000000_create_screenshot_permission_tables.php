<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            $this->spatiePermissionMigration()->up();
        }

        // tests/fixtures/Models/User::isGlobalAdmin() filters on
        // model_has_roles.team_id IS NULL. With teams disabled Spatie's stub
        // creates no team_id column, and SQLite silently treats the unknown
        // quoted identifier as a string literal — the predicate is then always
        // false, the workbench admin never counts as a global actor, and
        // SiteScope hides every page (every /admin/pages/{id}/edit URL 404s).
        // Provide the nullable column so the teams-aware query behaves; with
        // teams disabled at runtime Spatie ignores it entirely.
        if (! Schema::hasColumn('model_has_roles', 'team_id')) {
            Schema::table('model_has_roles', static function (Blueprint $table): void {
                $table->unsignedBigInteger('team_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            $this->spatiePermissionMigration()->down();
        }
    }

    private function spatiePermissionMigration(): Migration
    {
        $migration = require dirname(__DIR__, 2) . '/vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub';

        assert($migration instanceof Migration);

        return $migration;
    }
};
