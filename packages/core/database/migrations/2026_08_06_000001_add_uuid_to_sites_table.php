<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @contract-migration-approved Column redefined via ->change() only to tighten nullability after the uuid backfill loop above has populated every row; no data is dropped. */
    public function up(): void
    {
        TableName::existsAndIsOwned('sites', '2026_05_10_190832_05_create_sites_table');

        Schema::table(TableName::resolve('sites'), function (Blueprint $table): void {
            $table->uuid('uuid')->nullable()->unique(TableName::namedIndex('sites', 'sites_uuid_unique'));
        });

        DB::table(TableName::resolve('sites'))
            ->whereNull('uuid')
            ->orderBy('id')
            ->eachById(function (object $site): void {
                DB::table(TableName::resolve('sites'))
                    ->where('id', $site->id)
                    ->update(['uuid' => (string) Str::uuid()]);
            });

        Schema::table(TableName::resolve('sites'), function (Blueprint $table): void {
            $table->uuid('uuid')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        TableName::existsAndIsOwned('sites', '2026_05_10_190832_05_create_sites_table');

        Schema::table(TableName::resolve('sites'), function (Blueprint $table): void {
            $table->dropUnique(TableName::namedIndex('sites', 'sites_uuid_unique'));
            $table->dropColumn('uuid');
        });
    }
};
