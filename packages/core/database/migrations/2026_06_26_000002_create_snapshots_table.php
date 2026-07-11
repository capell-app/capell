<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aggregate snapshots used to bound event-replay cost. Raw events are never
 * pruned (full history is the point); snapshots are an optimisation layer.
 *
 * Mirrors spatie/laravel-event-sourcing's shipped schema verbatim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('snapshots', function (Blueprint $table): void {
            $table->id();
            $table->uuid('aggregate_uuid');
            $table->unsignedBigInteger('aggregate_version');
            $table->jsonb('state');
            $table->timestamps();

            $table->index('aggregate_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snapshots');
    }
};
