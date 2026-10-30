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
        Schema::create(TableName::resolve('capell_reporting_incidents'), function (Blueprint $table): void {
            $table->string('fingerprint', 64)->primary();
            $table->json('signal');
            $table->string('status', 32);
            $table->string('owner', 64)->nullable();
            $table->string('backup', 64)->nullable();
            $table->boolean('health')->default(false);
            $table->json('deliveries');
            $table->boolean('delivery_failed')->default(false);
            $table->timestamp('checked_at')->nullable()->index(TableName::index('capell_reporting_incidents', 'checked_at', 'index'));
            // Older MariaDB assigns an invalid zero default to later required TIMESTAMP columns.
            $table->dateTime('opened_at');
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('resolved_at')->nullable()->index(TableName::index('capell_reporting_incidents', 'resolved_at', 'index'));
            $table->string('acknowledged_by', 64)->nullable();
            $table->string('claim_token', 64)->nullable();
            $table->unsignedBigInteger('claim_until')->default(0);
            $table->timestamps();
            $table->index(['health', 'status'], TableName::index('capell_reporting_incidents', ['health', 'status'], 'index'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('capell_reporting_incidents'));
    }
};
