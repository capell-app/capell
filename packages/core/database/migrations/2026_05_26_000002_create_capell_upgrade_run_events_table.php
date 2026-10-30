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
        Schema::create(TableName::resolve('capell_upgrade_run_events'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('upgrade_run_id')
                ->constrained(TableName::resolve('capell_upgrade_runs'), indexName: TableName::index('capell_upgrade_run_events', 'upgrade_run_id', 'foreign'))
                ->restrictOnDelete();
            $table->string('level');
            $table->string('stage')->nullable();
            $table->string('message');
            $table->json('context')->nullable();
            $table->text('output_excerpt')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['upgrade_run_id', 'occurred_at'], TableName::namedIndex('capell_upgrade_run_events', 'capell_upgrade_run_events_run_time_idx'));
            $table->index(['level', 'occurred_at'], TableName::namedIndex('capell_upgrade_run_events', 'capell_upgrade_run_events_level_time_idx'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('capell_upgrade_run_events'));
    }
};
