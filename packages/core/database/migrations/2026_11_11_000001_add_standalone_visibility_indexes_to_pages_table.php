<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The every-minute scheduled-publication scan filters pages by visible_from or
 * visible_until without a site, so the leading-site_id composites cannot serve it.
 */
return new class extends Migration
{
    public function up(): void
    {
        TableName::existsAndIsOwned('pages', '2026_05_10_190832_12_create_pages_table');

        foreach (['visible_from', 'visible_until'] as $column) {
            $name = TableName::index('pages', [$column], 'index');

            if (Schema::hasIndex(TableName::resolve('pages'), $name)) {
                continue;
            }

            Schema::table(TableName::resolve('pages'), function (Blueprint $table) use ($column, $name): void {
                $table->index([$column], $name);
            });
        }
    }

    public function down(): void
    {
        foreach (['visible_from', 'visible_until'] as $column) {
            $name = TableName::index('pages', [$column], 'index');

            if (! Schema::hasIndex(TableName::resolve('pages'), $name)) {
                continue;
            }

            Schema::table(TableName::resolve('pages'), function (Blueprint $table) use ($name): void {
                $table->dropIndex($name);
            });
        }
    }
};
