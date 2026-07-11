<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_instances', function (Blueprint $table): void {
            $table->id();
            $table->uuid('instance_id')->unique();
            $table->text('signing_secret_encrypted');
            $table->string('connection_mode')->default('account_linked')->index();
            $table->string('account_id')->nullable()->index();
            $table->string('account_name')->nullable();
            $table->string('account_email')->nullable();
            $table->timestamp('account_email_verified_at')->nullable();
            $table->json('connection_metadata')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_instances');
    }
};
