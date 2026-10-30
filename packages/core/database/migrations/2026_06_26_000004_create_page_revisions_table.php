<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lightweight index of revision events (one row per state-bearing event) so
 * the admin history timeline lists fast without scanning stored_events. The
 * authoritative payload still lives in the event store; this is a denormalised
 * pointer carrying just what the timeline needs to render a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(TableName::resolve('page_revisions'), function (Blueprint $table): void {
            $table->id();
            $table->char('page_uuid', 36);
            $table->unsignedBigInteger('version');
            $table->foreignUserId('actor_id')->nullable()->constrained('users', indexName: TableName::index('page_revisions', 'actor_id', 'foreign'))->nullOnDelete();
            $table->string('summary');
            $table->boolean('is_rollback')->default(false);
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(['page_uuid', 'version'], TableName::index('page_revisions', ['page_uuid', 'version'], 'unique'));
            $table->index(['page_uuid', 'occurred_at'], TableName::index('page_revisions', ['page_uuid', 'occurred_at'], 'index'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('page_revisions'));
    }
};
