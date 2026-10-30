<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
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
        Schema::create(TableName::resolve('page_urls'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained(TableName::resolve('sites'), indexName: TableName::index('page_urls', 'site_id', 'foreign'))->cascadeOnDelete();
            $table->foreignId('language_id')->constrained(TableName::resolve('languages'), indexName: TableName::index('page_urls', 'language_id', 'foreign'))->cascadeOnDelete();
            $table->nullableMorphs('pageable', TableName::index('page_urls', ['pageable_type', 'pageable_id'], 'index'));
            $table->string('url', 191)->index(TableName::index('page_urls', 'url', 'index'));
            $table->text('target_url')->nullable();
            $table->smallInteger('status_code')->default(301);
            $table->boolean('is_manual')->default(false);
            $table->unsignedInteger('hit_count')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->text('notes')->nullable();
            $table->enum('type', ['alias', 'redirect'])->nullable()->index(TableName::index('page_urls', 'type', 'index'));
            $table->boolean('status')->index(TableName::index('page_urls', 'status', 'index'))->default(1);
            $table->userstamps();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['site_id', 'language_id', 'pageable_type', 'pageable_id'], TableName::namedIndex('page_urls', 'page_urls_site_language_pageable_index'));
            $table->unique(
                ['site_id', 'language_id', 'url', 'deleted_at'],
                TableName::namedIndex('page_urls', 'page_urls_site_language_url_unique'),
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('page_urls'));
    }
};
