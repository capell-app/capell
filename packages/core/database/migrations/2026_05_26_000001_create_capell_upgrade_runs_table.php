<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capell_upgrade_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('status');
            $table->boolean('dry_run')->default(false);
            $table->foreignId('user_id')->nullable()->index();
            $table->json('options')->nullable();
            $table->json('manual_commands')->nullable();
            $table->json('readiness_warnings')->nullable();
            $table->json('readiness_errors')->nullable();
            $table->string('current_stage')->nullable();
            $table->text('failure_reason')->nullable();
            $table->text('output_excerpt')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('created_at', 'capell_upgrade_runs_created_idx');
            $table->index(['status', 'created_at'], 'capell_upgrade_runs_status_created_idx');
            $table->index(['dry_run', 'status'], 'capell_upgrade_runs_dry_run_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capell_upgrade_runs');
    }
};
