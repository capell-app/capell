<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('taxonomy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('terms')->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->string('semantic')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['taxonomy_id', 'slug'], 'terms_taxonomy_slug_unique');
            $table->index(['taxonomy_id', 'parent_id'], 'terms_taxonomy_parent_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terms');
    }
};
