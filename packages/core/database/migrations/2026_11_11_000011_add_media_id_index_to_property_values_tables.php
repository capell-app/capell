<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes property values' `media_id`. MySQL and MariaDB index a foreign key column themselves;
 * the other drivers, and installs whose media table is left unconstrained, need this index so media
 * lookups, usage counts and the ON DELETE SET NULL check do not scan the whole table.
 */
return new class extends Migration
{
    private const array VALUE_TABLES = ['page_property_values', 'term_property_values'];

    public function up(): void
    {
        foreach (self::VALUE_TABLES as $baseTable) {
            $values = TableName::resolve($baseTable);
            if (! Schema::hasTable($values)) {
                continue;
            }

            if (Schema::hasIndex($values, ['media_id'])) {
                continue;
            }

            Schema::table($values, static function (Blueprint $table) use ($baseTable): void {
                $table->index('media_id', TableName::index($baseTable, 'media_id', 'index'));
            });
        }
    }

    public function down(): void
    {
        foreach (self::VALUE_TABLES as $baseTable) {
            $values = TableName::resolve($baseTable);
            $indexName = TableName::index($baseTable, 'media_id', 'index');
            // Only this migration's own index: an index MySQL made for the foreign key stays with the key.
            if (! Schema::hasTable($values)) {
                continue;
            }

            if (! Schema::hasIndex($values, $indexName)) {
                continue;
            }

            Schema::table($values, static function (Blueprint $table) use ($indexName): void {
                $table->dropIndex($indexName);
            });
        }
    }
};
