<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
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
        if (TableName::existsAndIsOwned('asset_attachments', basename(__FILE__, '.php'))) {
            return;
        }

        if (TableName::existsAndIsOwned('asset_relations', '2026_05_10_190832_15_create_asset_relations_table')) {
            Schema::rename(TableName::resolve('asset_relations'), TableName::resolve('asset_attachments'));

            return;
        }

        Schema::create(TableName::resolve('asset_attachments'), function (Blueprint $table): void {
            $table->id();
            $table->uuidMorphs('related', TableName::index('asset_attachments', ['related_type', 'related_id'], 'index'));
            $table->uuidMorphs('asset', TableName::index('asset_attachments', ['asset_type', 'asset_id'], 'index'));
            $table->unsignedInteger('order')->default(0);
            $table->userstamps();
            $table->timestamps();
            $table->unique(['related_type', 'related_id', 'asset_type', 'asset_id', 'order'], TableName::namedIndex('asset_attachments', 'related_asset_index'));
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('asset_attachments'));
    }
};
