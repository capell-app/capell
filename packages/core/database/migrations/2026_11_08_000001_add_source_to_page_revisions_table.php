<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records where each page version came from (an edit, a rollback, an import or
 * a package publish) and an optional human label, so the single versions store
 * can describe every version. Both columns are nullable: rows indexed before
 * this migration keep working and resolve to Edit or Rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = TableName::resolve('page_revisions');

        Schema::table($table, function (Blueprint $blueprint) use ($table): void {
            if (! Schema::hasColumn($table, 'source')) {
                $blueprint->string('source', 32)->nullable()->after('is_rollback');
            }

            if (! Schema::hasColumn($table, 'label')) {
                $blueprint->string('label')->nullable()->after('source');
            }
        });
    }

    public function down(): void
    {
        $table = TableName::resolve('page_revisions');

        Schema::table($table, function (Blueprint $blueprint) use ($table): void {
            foreach (['label', 'source'] as $column) {
                if (Schema::hasColumn($table, $column)) {
                    $blueprint->dropColumn($column);
                }
            }
        });
    }
};
