<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An asset attachment's related_id and asset_id hold the key of any model, most of which are
 * numeric. Laravel's mariadb driver creates uuid columns as MariaDB's native UUID type, which
 * refuses a numeric key and cannot be compared with a numeric column. Store the keys as the
 * CHAR(36) the mysql driver has always created. Other drivers already hold text and are left alone.
 */
return new class extends Migration
{
    private const array Columns = ['related_id', 'asset_id'];

    public function up(): void
    {
        if (! TableName::existsAndIsOwned('asset_attachments', '2026_05_10_190832_15_create_asset_attachments_table')) {
            return;
        }

        $table = TableName::resolve('asset_attachments');

        foreach (self::Columns as $column) {
            if (Schema::getColumnType($table, $column) !== 'uuid') {
                continue;
            }

            Schema::table($table, static function (Blueprint $blueprint) use ($column): void {
                $blueprint->char($column, 36)->change();
            });
        }
    }

    public function down(): void
    {
        // Deliberately not reversed: a native UUID column would reject the numeric keys stored since.
    }
};
