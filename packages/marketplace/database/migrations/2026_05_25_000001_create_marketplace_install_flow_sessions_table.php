<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_install_flow_sessions', function (Blueprint $table): void {
            $table->id();
            $table->string('remote_flow_id')->nullable()->unique();
            $table->string('status')->default('pending')->index();
            $table->unsignedTinyInteger('contract_version')->default(1);
            $table->json('selected_extensions');
            $table->json('quoted_extensions')->nullable();
            $table->unsignedInteger('quoted_price_cents')->default(0);
            $table->string('quoted_currency', 3)->nullable();
            $table->json('remote_entitlement_ids')->nullable();
            $table->json('last_exchange_payload')->nullable();
            $table->json('transition_log')->nullable();
            $table->json('install_options')->nullable();
            $table->json('dependency_snapshot')->nullable();
            $table->json('user_context')->nullable();
            $table->string('state_hash', 64)->unique();
            $table->string('code_verifier_hash', 64);
            $table->text('code_verifier_encrypted');
            $table->string('approval_url', 1024)->nullable();
            $table->string('return_url', 1024)->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('redirected_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->string('failure_reason')->nullable()->index();
            $table->json('failure_metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_install_flow_sessions');
    }
};
