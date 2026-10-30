<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(TableName::resolve('terms'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('taxonomy_id')->constrained(TableName::resolve('taxonomies'), indexName: TableName::index('terms', 'taxonomy_id', 'foreign'))->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained(TableName::resolve('terms'), indexName: TableName::index('terms', 'parent_id', 'foreign'))->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->string('semantic')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['taxonomy_id', 'slug'], TableName::namedIndex('terms', 'terms_taxonomy_slug_unique'));
            $table->index(['taxonomy_id', 'parent_id'], TableName::namedIndex('terms', 'terms_taxonomy_parent_index'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('terms'));
    }
};
