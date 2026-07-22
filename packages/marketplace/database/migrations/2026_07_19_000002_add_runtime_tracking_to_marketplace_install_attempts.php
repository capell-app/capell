<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_install_attempts', function (Blueprint $table): void {
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->string('current_stage')->nullable()->index();
            $table->unsignedInteger('progress_current')->default(0);
            $table->unsignedInteger('progress_total')->nullable();
            $table->dateTime('heartbeat_at')->nullable()->index();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedBigInteger('runtime_ms')->nullable();
            $table->unsignedBigInteger('peak_memory_bytes')->nullable();
            $table->unsignedInteger('query_count')->default(0);
            $table->json('stage_telemetry')->nullable();
            $table->json('failure_context')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_install_attempts', function (Blueprint $table): void {
            $table->dropUnique(['idempotency_key']);
            $table->dropIndex(['current_stage']);
            $table->dropIndex(['heartbeat_at']);
            $table->dropColumn([
                'idempotency_key',
                'current_stage',
                'progress_current',
                'progress_total',
                'heartbeat_at',
                'attempt_count',
                'runtime_ms',
                'peak_memory_bytes',
                'query_count',
                'stage_telemetry',
                'failure_context',
            ]);
        });
    }
};
