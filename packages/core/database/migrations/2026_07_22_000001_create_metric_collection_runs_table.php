<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metric_collection_runs', function (Blueprint $table): void {
            $table->id();
            $table->date('day');
            $table->string('owner_package', 191);
            $table->string('collector_key', 120);
            $table->char('definition_hash', 64);
            $table->string('status', 32)->default('started');
            $table->string('source_watermark', 512)->nullable();
            $table->char('source_checksum', 64)->nullable();
            $table->string('error_summary', 1000)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(
                ['day', 'owner_package', 'collector_key'],
                'metric_collection_runs_collector_day_idx',
            );
            $table->index(['status', 'started_at'], 'metric_collection_runs_status_started_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_collection_runs');
    }
};
