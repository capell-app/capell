<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_graph_edges', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type', 191);
            $table->unsignedBigInteger('source_id');
            $table->string('target_type', 191);
            $table->unsignedBigInteger('target_id');
            $table->string('kind', 64);
            $table->string('strength', 32);
            $table->string('source_package', 128);
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('language_id')->nullable()->constrained('languages')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
            $table->index(['target_type', 'target_id']);
            $table->index(['site_id', 'language_id']);
            $table->unique(
                ['source_type', 'source_id', 'target_type', 'target_id', 'kind', 'source_package'],
                'content_graph_edges_unique_edge',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_graph_edges');
    }
};
