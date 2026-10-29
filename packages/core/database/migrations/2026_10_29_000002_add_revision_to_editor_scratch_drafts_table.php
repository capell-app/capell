<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('editor_scratch_drafts')) {
            return;
        }

        foreach (['revision', 'previous_revision'] as $column) {
            if (! Schema::hasColumn('editor_scratch_drafts', $column)) {
                Schema::table('editor_scratch_drafts', fn (Blueprint $table) => $table->uuid($column)->nullable());
            }
        }
    }

    public function down(): void
    {
        // Recovery data and its concurrency token must survive rollback.
    }
};
