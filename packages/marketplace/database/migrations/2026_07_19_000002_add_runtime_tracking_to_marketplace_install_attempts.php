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
        TableName::existsAndIsOwned('marketplace_install_attempts', '2026_05_10_190837_09_create_marketplace_install_attempts_table');

        if (Schema::hasColumns(TableName::resolve('marketplace_install_attempts'), [
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
        ])) {
            return;
        }

        Schema::table(TableName::resolve('marketplace_install_attempts'), function (Blueprint $table): void {
            $table->string('idempotency_key', 64)->nullable()->unique(TableName::index('marketplace_install_attempts', 'idempotency_key', 'unique'));
            $table->string('current_stage')->nullable()->index(TableName::index('marketplace_install_attempts', 'current_stage', 'index'));
            $table->unsignedInteger('progress_current')->default(0);
            $table->unsignedInteger('progress_total')->nullable();
            $table->dateTime('heartbeat_at')->nullable()->index(TableName::index('marketplace_install_attempts', 'heartbeat_at', 'index'));
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
        TableName::existsAndIsOwned('marketplace_install_attempts', '2026_05_10_190837_09_create_marketplace_install_attempts_table');

        Schema::table(TableName::resolve('marketplace_install_attempts'), function (Blueprint $table): void {
            $table->dropUnique(TableName::index('marketplace_install_attempts', ['idempotency_key'], 'unique'));
            $table->dropIndex(TableName::index('marketplace_install_attempts', ['current_stage'], 'index'));
            $table->dropIndex(TableName::index('marketplace_install_attempts', ['heartbeat_at'], 'index'));
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
