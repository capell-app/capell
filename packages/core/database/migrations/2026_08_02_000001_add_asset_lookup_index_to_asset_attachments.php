<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string Index = 'asset_attachments_asset_lookup_index';

    public function up(): void
    {
        if (! Schema::hasTable('asset_attachments') || Schema::hasIndex('asset_attachments', self::Index)) {
            return;
        }

        Schema::table('asset_attachments', function (Blueprint $table): void {
            $table->index(['asset_type', 'asset_id'], self::Index);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('asset_attachments') || ! Schema::hasIndex('asset_attachments', self::Index)) {
            return;
        }

        Schema::table('asset_attachments', function (Blueprint $table): void {
            $table->dropIndex(self::Index);
        });
    }
};
