<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketplace_install_attempts')) {
            Schema::create('marketplace_install_attempts', function (Blueprint $table): void {
                $table->id();
                $table->string('composer_name')->index();
                $table->string('extension_slug')->index();
                $table->string('extension_name');
                $table->string('kind')->index();
                $table->string('status')->index();
                $table->text('composer_command')->nullable();
                $table->string('version_constraint')->nullable();
                $table->json('requested_options')->nullable();
                $table->json('eligibility')->nullable();
                $table->json('context')->nullable();
                $table->json('diagnostic_context')->nullable();
                $table->json('deployment')->nullable();
                $table->text('failure_reason')->nullable();
                $table->string('failure_type')->nullable()->index();
                $table->string('failure_stage')->nullable()->index();
                $table->foreignId('retry_of_id')->nullable()->constrained('marketplace_install_attempts')->nullOnDelete();
                $table->string('retried_by_id')->nullable()->index();
                $table->timestamp('retried_at')->nullable();
                $table->text('output_excerpt')->nullable();
                $table->text('error_excerpt')->nullable();
                $table->string('telemetry_status')->nullable()->index();
                $table->timestamp('telemetry_attempted_at')->nullable();
                $table->timestamp('telemetry_synced_at')->nullable();
                $table->text('telemetry_failure')->nullable();
                $table->string('user_id')->nullable()->index();
                $table->string('user_email')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamp('queued_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('cancel_requested_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamps();

                $table->index(['kind', 'status']);
                $table->index(['composer_name', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_install_attempts');
    }
};
