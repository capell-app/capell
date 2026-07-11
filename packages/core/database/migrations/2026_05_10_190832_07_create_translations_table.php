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
        Schema::create('translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            $table->morphs('translatable');
            $table->string('title')->nullable();
            $table->longText('content')->nullable();
            $table->json('meta')->nullable();
            $table->userstamps();
            $table->timestamps();
            $table->unique(['language_id', 'translatable_type', 'translatable_id'], 'translations_key_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('translations');
    }
};
