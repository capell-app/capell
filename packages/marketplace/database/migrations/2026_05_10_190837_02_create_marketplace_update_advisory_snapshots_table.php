<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketplace_update_advisory_snapshots')) {
            Schema::create('marketplace_update_advisory_snapshots', function (Blueprint $table): void {
                $table->id();
                $table->string('source');
                $table->timestamp('checked_at');
                $table->string('capell_version')->nullable();
                $table->json('updates')->nullable();
                $table->json('advisories')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['checked_at', 'source']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_update_advisory_snapshots');
    }
};
