<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metric_events', function (Blueprint $table): void {
            $table->id();
            $table->dateTime('occurred_at');
            $table->string('owner_package', 191);
            $table->string('collector_key', 120);
            $table->string('metric_key', 120);
            $table->char('definition_hash', 64);
            $table->string('scope_key', 191);
            $table->string('scope_type', 32);
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->uuid('site_uuid')->nullable();
            $table->string('language', 35)->nullable();
            $table->string('timezone', 64);
            $table->time('day_starts_at');
            $table->bigInteger('value');
            $table->unsignedInteger('weight')->default(1);
            $table->timestamps();

            $table->index(['occurred_at', 'id'], 'metric_events_occurred_id_idx');
            $table->index(
                ['owner_package', 'collector_key', 'metric_key', 'definition_hash', 'scope_key'],
                'metric_events_identity_scope_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_events');
    }
};
