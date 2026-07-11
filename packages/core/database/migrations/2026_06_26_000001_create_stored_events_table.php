<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only event store backing Capell's event sourcing.
 *
 * Mirrors spatie/laravel-event-sourcing's shipped schema verbatim so the
 * stock EloquentStoredEventRepository works unchanged; only the registration
 * is Capell-owned (see HasMigrations). `jsonb` degrades to json/TEXT on
 * MySQL and SQLite, so the column type is portable across Capell's drivers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stored_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('aggregate_uuid')->nullable();
            $table->unsignedBigInteger('aggregate_version')->nullable();
            $table->unsignedTinyInteger('event_version')->default(1);
            $table->string('event_class');
            $table->jsonb('event_properties');
            $table->jsonb('meta_data');
            $table->timestamp('created_at');

            $table->index('event_class');
            $table->index('aggregate_uuid');
            $table->unique(['aggregate_uuid', 'aggregate_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stored_events');
    }
};
