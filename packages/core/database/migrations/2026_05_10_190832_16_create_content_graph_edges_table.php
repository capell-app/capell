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
        Schema::create(TableName::resolve('content_graph_edges'), function (Blueprint $table): void {
            $table->id();
            $table->string('source_type', 191);
            $table->unsignedBigInteger('source_id');
            $table->string('target_type', 191);
            $table->unsignedBigInteger('target_id');
            $table->string('kind', 64);
            $table->string('strength', 32);
            $table->string('source_package', 128);
            $table->foreignId('site_id')->nullable()->constrained(TableName::resolve('sites'), indexName: TableName::index('content_graph_edges', 'site_id', 'foreign'))->nullOnDelete();
            $table->foreignId('language_id')->nullable()->constrained(TableName::resolve('languages'), indexName: TableName::index('content_graph_edges', 'language_id', 'foreign'))->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'source_id'], TableName::index('content_graph_edges', ['source_type', 'source_id'], 'index'));
            $table->index(['target_type', 'target_id'], TableName::index('content_graph_edges', ['target_type', 'target_id'], 'index'));
            $table->index(['site_id', 'language_id'], TableName::index('content_graph_edges', ['site_id', 'language_id'], 'index'));
            $table->unique(
                ['source_type', 'source_id', 'target_type', 'target_id', 'kind', 'source_package'],
                TableName::namedIndex('content_graph_edges', 'content_graph_edges_unique_edge'),
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('content_graph_edges'));
    }
};
