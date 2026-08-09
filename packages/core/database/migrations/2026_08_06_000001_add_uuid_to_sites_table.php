<?php

declare(strict_types=1);

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
        Schema::table('sites', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable()->unique('sites_uuid_unique');
        });

        DB::table('sites')
            ->whereNull('uuid')
            ->orderBy('id')
            ->eachById(function (object $site): void {
                DB::table('sites')
                    ->where('id', $site->id)
                    ->update(['uuid' => (string) Str::uuid()]);
            });

        Schema::table('sites', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropUnique('sites_uuid_unique');
            $table->dropColumn('uuid');
        });
    }
};
