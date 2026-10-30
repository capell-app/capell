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
        if (! TableName::existsAndIsOwned('marketplace_update_advisory_snapshots', basename(__FILE__, '.php'))) {
            Schema::create(TableName::resolve('marketplace_update_advisory_snapshots'), function (Blueprint $table): void {
                $table->id();
                $table->string('source');
                $table->timestamp('checked_at');
                $table->string('capell_version')->nullable();
                $table->json('updates')->nullable();
                $table->json('advisories')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['checked_at', 'source'], TableName::index('marketplace_update_advisory_snapshots', ['checked_at', 'source'], 'index'));
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('marketplace_update_advisory_snapshots'));
    }
};
