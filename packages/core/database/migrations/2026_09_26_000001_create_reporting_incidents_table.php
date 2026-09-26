<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capell_reporting_incidents', function (Blueprint $table): void {
            $table->string('fingerprint', 64)->primary();
            $table->json('signal');
            $table->string('status', 32);
            $table->string('owner', 64)->nullable();
            $table->string('backup', 64)->nullable();
            $table->boolean('health')->default(false);
            $table->json('deliveries');
            $table->boolean('delivery_failed')->default(false);
            $table->timestamp('checked_at')->nullable()->index();
            $table->timestamp('opened_at');
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->string('acknowledged_by', 64)->nullable();
            $table->string('claim_token', 64)->nullable();
            $table->unsignedBigInteger('claim_until')->default(0);
            $table->timestamps();
            $table->index(['health', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capell_reporting_incidents');
    }
};
