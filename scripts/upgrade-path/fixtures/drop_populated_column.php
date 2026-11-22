<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seeded fault for scripts/upgrade-path.sh: a migration that drops a column holding site data.
 * The check names the column in UPGRADE_PATH_DROP_TABLE and UPGRADE_PATH_DROP_COLUMN.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = (string) getenv('UPGRADE_PATH_DROP_TABLE');
        $column = (string) getenv('UPGRADE_PATH_DROP_COLUMN');

        throw_if($table === '' || $column === '', RuntimeException::class, 'The seeded destructive migration was run without a column to drop.');

        Schema::table($table, static function (Blueprint $blueprint) use ($column): void {
            $blueprint->dropColumn($column);
        });
    }
};
