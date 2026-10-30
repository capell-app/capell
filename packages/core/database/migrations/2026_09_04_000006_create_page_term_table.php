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
        Schema::create(TableName::resolve('page_term'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('page_id')->constrained(TableName::resolve('pages'), indexName: TableName::index('page_term', 'page_id', 'foreign'))->cascadeOnDelete();
            $table->foreignId('term_id')->constrained(TableName::resolve('terms'), indexName: TableName::index('page_term', 'term_id', 'foreign'))->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['page_id', 'term_id'], TableName::namedIndex('page_term', 'page_term_pair_unique'));
            $table->index(['page_id', 'position'], TableName::namedIndex('page_term', 'page_term_page_position_index'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('page_term'));
    }
};
