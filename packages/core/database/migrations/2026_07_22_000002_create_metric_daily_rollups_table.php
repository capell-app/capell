<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metric_daily_rollups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('metric_collection_run_id')
                ->constrained('metric_collection_runs')
                ->restrictOnDelete();
            $table->date('day');
            $table->string('owner_package', 191);
            $table->string('collector_key', 120);
            $table->string('metric_key', 120);
            $table->char('definition_hash', 64);
            $table->string('scope_key', 191);
            $table->string('scope_type', 32);
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->uuid('site_uuid')->nullable();
            $table->string('language', 35)->nullable();
            $table->string('timezone', 64);
            $table->time('day_starts_at');
            $table->string('unit', 32);
            $table->string('value_type', 32);
            $table->string('value', 255)->nullable();
            $table->unsignedTinyInteger('scale')->nullable();
            $table->char('currency', 3)->default('');
            $table->string('point_state', 32)->default('present');
            $table->timestamps();

            $table->unique(
                ['day', 'owner_package', 'collector_key', 'metric_key', 'scope_key'],
                'metric_daily_rollups_identity_unique',
            );
            $table->index(['site_uuid', 'day'], 'metric_daily_rollups_site_day_idx');
            $table->index(['metric_collection_run_id', 'metric_key'], 'metric_daily_rollups_run_metric_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_daily_rollups');
    }
};
