<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketplace_install_attempt_events')) {
            Schema::create('marketplace_install_attempt_events', function (Blueprint $table): void {
                $table->id();
                $table
                    ->foreignId('marketplace_install_attempt_id')
                    ->constrained('marketplace_install_attempts', indexName: 'mkp_install_events_attempt_fk')
                    ->cascadeOnDelete();
                $table->string('level')->index();
                $table->string('stage')->nullable()->index();
                $table->string('message');
                $table->json('context')->nullable();
                $table->text('output_excerpt')->nullable();
                $table->timestamp('occurred_at')->index();
                $table->timestamps();

                $table->index(['marketplace_install_attempt_id', 'occurred_at'], 'mkp_install_events_attempt_occurred_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_install_attempt_events');
    }
};
