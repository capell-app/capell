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
        if (! TableName::existsAndIsOwned('marketplace_install_attempts', basename(__FILE__, '.php'))) {
            Schema::create(TableName::resolve('marketplace_install_attempts'), function (Blueprint $table): void {
                $table->id();
                $table->string('composer_name')->index(TableName::index('marketplace_install_attempts', 'composer_name', 'index'));
                $table->string('extension_slug')->index(TableName::index('marketplace_install_attempts', 'extension_slug', 'index'));
                $table->string('extension_name');
                $table->string('kind')->index(TableName::index('marketplace_install_attempts', 'kind', 'index'));
                $table->string('status')->index(TableName::index('marketplace_install_attempts', 'status', 'index'));
                $table->text('composer_command')->nullable();
                $table->string('version_constraint')->nullable();
                $table->json('requested_options')->nullable();
                $table->json('eligibility')->nullable();
                $table->json('context')->nullable();
                $table->json('diagnostic_context')->nullable();
                $table->json('deployment')->nullable();
                $table->text('failure_reason')->nullable();
                $table->string('failure_type')->nullable()->index(TableName::index('marketplace_install_attempts', 'failure_type', 'index'));
                $table->string('failure_stage')->nullable()->index(TableName::index('marketplace_install_attempts', 'failure_stage', 'index'));
                $table->foreignId('retry_of_id')->nullable()->constrained(TableName::resolve('marketplace_install_attempts'), indexName: TableName::index('marketplace_install_attempts', 'retry_of_id', 'foreign'))->nullOnDelete();
                $table->string('retried_by_id')->nullable()->index(TableName::index('marketplace_install_attempts', 'retried_by_id', 'index'));
                $table->timestamp('retried_at')->nullable();
                $table->text('output_excerpt')->nullable();
                $table->text('error_excerpt')->nullable();
                $table->string('telemetry_status')->nullable()->index(TableName::index('marketplace_install_attempts', 'telemetry_status', 'index'));
                $table->timestamp('telemetry_attempted_at')->nullable();
                $table->timestamp('telemetry_synced_at')->nullable();
                $table->text('telemetry_failure')->nullable();
                $table->string('user_id')->nullable()->index(TableName::index('marketplace_install_attempts', 'user_id', 'index'));
                $table->string('user_email')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamp('queued_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('cancel_requested_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamps();

                $table->index(['kind', 'status'], TableName::index('marketplace_install_attempts', ['kind', 'status'], 'index'));
                $table->index(['composer_name', 'status'], TableName::index('marketplace_install_attempts', ['composer_name', 'status'], 'index'));
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('marketplace_install_attempts'));
    }
};
