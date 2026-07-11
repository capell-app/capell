<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('asset_attachments')) {
            return;
        }

        if (Schema::hasTable('asset_relations')) {
            Schema::rename('asset_relations', 'asset_attachments');

            return;
        }

        Schema::create('asset_attachments', function (Blueprint $table): void {
            $table->id();
            $table->uuidMorphs('related');
            $table->uuidMorphs('asset');
            $table->unsignedInteger('order')->default(0);
            $table->userstamps();
            $table->timestamps();
            $table->unique(['related_type', 'related_id', 'asset_type', 'asset_id', 'order'], 'related_asset_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_attachments');
    }
};
