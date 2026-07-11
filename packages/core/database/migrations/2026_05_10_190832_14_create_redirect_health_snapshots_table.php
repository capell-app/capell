<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('redirect_health_snapshots')) {
            return;
        }

        Schema::create('redirect_health_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('page_url_id')->constrained('page_urls')->cascadeOnDelete();
            $table->text('source_url');
            $table->text('target_url')->nullable();
            $table->boolean('has_chain')->default(false);
            $table->boolean('has_loop')->default(false);
            $table->unsignedSmallInteger('warning_count')->default(0);
            $table->unsignedSmallInteger('error_count')->default(0);
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique('page_url_id');
            $table->index(['has_chain', 'has_loop']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redirect_health_snapshots');
    }
};
