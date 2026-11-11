<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives property values' `media_id` the same policy as their page and term
 * references: a permanently deleted media item nulls the value that pointed
 * at it. A trashed media row still exists, so its references survive a
 * restore. Values already pointing at a missing media row are nulled first,
 * because they could never resolve and would block the constraint.
 */
return new class extends Migration
{
    private const array VALUE_TABLES = ['page_property_values', 'term_property_values'];

    public function up(): void
    {
        $media = TableName::external('media');

        if (! TableName::externalExistsAndIsOwned($media, 'create_media_table')) {
            return;
        }

        foreach (self::VALUE_TABLES as $baseTable) {
            $values = TableName::resolve($baseTable);
            if (! Schema::hasTable($values)) {
                continue;
            }

            if ($this->hasForeignKey($values, $baseTable)) {
                continue;
            }

            DB::table($values)
                ->whereNotNull('media_id')
                ->whereNotExists(fn (Builder $query): Builder => $query
                    ->selectRaw('1')
                    ->from($media)
                    ->whereColumn($media . '.id', $values . '.media_id'))
                ->update(['media_id' => null]);

            Schema::table($values, function (Blueprint $table) use ($baseTable, $media): void {
                $table->foreign('media_id', $this->foreignKeyName($baseTable))
                    ->references('id')
                    ->on($media)
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::VALUE_TABLES as $baseTable) {
            $values = TableName::resolve($baseTable);

            if (! Schema::hasTable($values)) {
                continue;
            }

            if (! $this->hasForeignKey($values, $baseTable)) {
                continue;
            }

            // Dropped by column: SQLite can only drop a foreign key by recreating the table from its
            // column list. Other drivers derive the conventional name, which is the name up() gave
            // the key unless a table prefix pushed it past 63 characters.
            Schema::table($values, static function (Blueprint $table): void {
                $table->dropForeign(['media_id']);
            });
        }
    }

    private function foreignKeyName(string $baseTable): string
    {
        return TableName::index($baseTable, 'media_id', 'foreign');
    }

    private function hasForeignKey(string $values, string $baseTable): bool
    {
        $name = $this->foreignKeyName($baseTable);

        foreach (Schema::getForeignKeys($values) as $foreignKey) {
            if ($foreignKey['name'] === $name || $foreignKey['columns'] === ['media_id']) {
                return true;
            }
        }

        return false;
    }
};
