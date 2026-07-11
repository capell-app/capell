<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('page_urls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            $table->nullableMorphs('pageable');
            $table->string('url', 191)->index();
            $table->text('target_url')->nullable();
            $table->smallInteger('status_code')->default(301);
            $table->boolean('is_manual')->default(false);
            $table->unsignedInteger('hit_count')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->text('notes')->nullable();
            $table->enum('type', ['alias', 'redirect'])->nullable()->index();
            $table->boolean('status')->index()->default(1);
            $table->userstamps();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['site_id', 'language_id', 'pageable_type', 'pageable_id'], 'page_urls_site_language_pageable_index');
            $table->unique(
                ['site_id', 'language_id', 'url', 'deleted_at'],
                'page_urls_site_language_url_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('page_urls');
    }
};
