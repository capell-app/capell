<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_visitors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('language', 32);
            $table->date('day');
            $table->string('visitor_hash', 32);
            $table->timestamp('first_seen_at');

            $table->unique(
                ['site_id', 'language', 'day', 'visitor_hash'],
                'activity_visitors_identity_unique',
            );
            $table->index(['site_id', 'first_seen_at'], 'activity_visitors_site_time_index');
            $table->index('day', 'activity_visitors_day_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_visitors');
    }
};
