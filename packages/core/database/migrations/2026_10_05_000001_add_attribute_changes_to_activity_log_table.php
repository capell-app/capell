<?php

declare(strict_types=1);

use Capell\Core\Support\Activity\ActivityLogCompat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $model = ActivityLogCompat::activityModelClass();
        $activity = new $model;
        $schema = Schema::connection($activity->getConnectionName());
        $table = $activity->getTable();

        if ($schema->hasTable($table) && ! $schema->hasColumn($table, 'attribute_changes')) {
            $schema->table($table, function (Blueprint $blueprint): void {
                $blueprint->json('attribute_changes')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Keep audit values on rollback: both supported majors tolerate the extra column.
    }
};
