<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capell_extensions', function (Blueprint $table): void {
            $table->id();
            $table->string('composer_name')->unique();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->string('version')->nullable();
            $table->string('source')->nullable();
            $table->string('status')->default('enabled')->index();
            $table->timestamp('enabled_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_paid_marketplace_extension')->default(false);
            $table->string('marketplace_runtime_status')->nullable()->index();
            $table->boolean('marketplace_runtime_allowed')->default(true);
            $table->json('marketplace_signed_activation')->nullable();
            $table->timestamp('marketplace_activation_checked_at')->nullable();
            $table->text('marketplace_runtime_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capell_extensions');
    }
};
