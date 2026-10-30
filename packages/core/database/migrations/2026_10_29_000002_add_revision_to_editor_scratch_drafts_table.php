<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = TableName::resolve('editor_scratch_drafts');
        if (! Schema::hasTable($table)) {
            return;
        }

        foreach (['revision', 'previous_revision'] as $column) {
            if (! Schema::hasColumn($table, $column)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->uuid($column)->nullable());
            }
        }
    }

    public function down(): void
    {
        // Recovery data and its concurrency token must survive rollback.
    }
};
