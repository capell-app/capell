<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketplace_install_intents')) {
            Schema::create('marketplace_install_intents', function (Blueprint $table): void {
                $table->id();
                $table->string('composer_name');
                $table->string('extension_slug');
                $table->string('extension_name');
                $table->string('kind');
                $table->string('status')->index();
                $table->text('composer_command');
                $table->string('version_constraint')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();

                $table->unique(['composer_name', 'kind']);
                $table->index(['kind', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_install_intents');
    }
};
