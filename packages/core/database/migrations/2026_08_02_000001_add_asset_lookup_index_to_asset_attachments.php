<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string Index = 'asset_attachments_asset_lookup_index';

    public function up(): void
    {
        if (! TableName::existsAndIsOwned('asset_attachments', '2026_05_10_190832_15_create_asset_attachments_table') || Schema::hasIndex(TableName::resolve('asset_attachments'), TableName::namedIndex('asset_attachments', self::Index))) {
            return;
        }

        Schema::table(TableName::resolve('asset_attachments'), function (Blueprint $table): void {
            $table->index(['asset_type', 'asset_id'], TableName::namedIndex('asset_attachments', self::Index));
        });
    }

    public function down(): void
    {
        if (! TableName::existsAndIsOwned('asset_attachments', '2026_05_10_190832_15_create_asset_attachments_table') || ! Schema::hasIndex(TableName::resolve('asset_attachments'), TableName::namedIndex('asset_attachments', self::Index))) {
            return;
        }

        Schema::table(TableName::resolve('asset_attachments'), function (Blueprint $table): void {
            $table->dropIndex(TableName::namedIndex('asset_attachments', self::Index));
        });
    }
};
