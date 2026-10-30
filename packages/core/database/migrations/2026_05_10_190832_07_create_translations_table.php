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
        Schema::create(TableName::resolve('translations'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('language_id')->constrained(TableName::resolve('languages'), indexName: TableName::index('translations', 'language_id', 'foreign'))->cascadeOnDelete();
            $table->morphs('translatable', TableName::index('translations', ['translatable_type', 'translatable_id'], 'index'));
            $table->string('title')->nullable();
            $table->longText('content')->nullable();
            $table->json('meta')->nullable();
            $table->userstamps();
            $table->timestamps();
            $table->unique(['language_id', 'translatable_type', 'translatable_id'], TableName::namedIndex('translations', 'translations_key_unique'));
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('translations'));
    }
};
