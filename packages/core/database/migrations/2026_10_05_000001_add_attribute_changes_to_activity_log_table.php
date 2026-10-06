<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    #[Override]
    public function shouldRun(): bool
    {
        [$schema, $table] = $this->activityTable();

        // The Migrator leaves skipped migrations pending until the vendor table exists.
        return $schema->hasTable($table);
    }

    public function up(): void
    {
        [$schema, $table] = $this->activityTable();
        throw_unless($schema->hasTable($table), LogicException::class, "The activity log table must exist before adding attribute_changes. Run this migration through Laravel's Migrator after publishing the vendor create migration.");

        if (! $schema->hasColumn($table, 'attribute_changes')) {
            $schema->table($table, function (Blueprint $blueprint): void {
                $blueprint->json('attribute_changes')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Keep audit values on rollback: both supported majors tolerate the extra column.
    }

    /** @return array{Builder, string} */
    private function activityTable(): array
    {
        // Follow the configured activity model when there is one, so a host that
        // renames the table through its own model is migrated too.
        $model = Config::get('activitylog.activity_model');

        if (is_string($model) && is_subclass_of($model, Model::class)) {
            $activity = new $model;

            return [Schema::connection($activity->getConnectionName()), $activity->getTable()];
        }

        $connection = Config::get('activitylog.database_connection');
        $table = Config::get('activitylog.table_name', 'activity_log');

        return [
            Schema::connection(is_string($connection) ? $connection : null),
            is_string($table) ? $table : 'activity_log',
        ];
    }
};
