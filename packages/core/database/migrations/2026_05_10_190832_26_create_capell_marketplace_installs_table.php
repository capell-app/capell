<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capell_marketplace_installs', function (Blueprint $table): void {
            $table->id();
            $table->string('install_id')->unique();
            $table->text('public_key')->nullable();
            $table->text('private_key_encrypted')->nullable();
            $table->string('site_url')->nullable();
            $table->string('environment')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('last_reported_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capell_marketplace_installs');
    }
};
