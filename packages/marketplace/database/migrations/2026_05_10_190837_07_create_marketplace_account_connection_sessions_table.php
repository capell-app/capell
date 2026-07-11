<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_account_connection_sessions', function (Blueprint $table): void {
            $table->id();
            $table->string('connection_session_id')->nullable()->unique('mp_account_sessions_connection_id_unique');
            $table->string('state_hash', 64)->unique();
            $table->string('code_verifier_hash', 64);
            $table->text('code_verifier_encrypted');
            $table->string('claimed_domain')->index();
            $table->string('app_url', 512);
            $table->string('callback_url', 512);
            $table->string('status')->default('pending')->index();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_account_connection_sessions');
    }
};
