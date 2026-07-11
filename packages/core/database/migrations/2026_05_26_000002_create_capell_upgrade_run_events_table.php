<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capell_upgrade_run_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('upgrade_run_id')
                ->constrained('capell_upgrade_runs')
                ->restrictOnDelete();
            $table->string('level');
            $table->string('stage')->nullable();
            $table->string('message');
            $table->json('context')->nullable();
            $table->text('output_excerpt')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['upgrade_run_id', 'occurred_at'], 'capell_upgrade_run_events_run_time_idx');
            $table->index(['level', 'occurred_at'], 'capell_upgrade_run_events_level_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capell_upgrade_run_events');
    }
};
