<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('layout_content_snapshots')) {
            return;
        }

        Schema::create('layout_content_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('layout_id')->constrained('layouts')->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('theme_id')->nullable()->constrained('themes')->nullOnDelete();
            $table->timestamp('taken_at')->index();
            $table->string('reason');
            $table->longText('containers_before')->nullable();
            $table->longText('admin_before')->nullable();
            $table->longText('meta_before')->nullable();
            $table->longText('elements_before')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['layout_id', 'taken_at'], 'layout_content_snapshots_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('layout_content_snapshots');
    }
};
