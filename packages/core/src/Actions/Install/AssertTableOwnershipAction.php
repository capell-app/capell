<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Exceptions\ForeignTableException;
use Capell\Core\Support\TableName;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Decides whether a create migration may skip because its table already exists.
 *
 * Returns true when the table exists and the creating migration is recorded (the table is ours,
 * so the create can be skipped), false when the table is absent (go ahead and create it) and
 * throws ForeignTableException when the table exists without that migration being recorded.
 * Package migrations call this instead of a bare Schema::hasTable() guard, so a Capell table is
 * never silently adopted from another application.
 */
final class AssertTableOwnershipAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  string  $table  A Capell base table name (resolved through the prefix and aliases), or, with
     *                         $configKey set, the final configured name of a table owned by another package.
     * @param  string  $migration  The creating migration, with or without its timestamp.
     * @param  string|null  $configKey  The config key that renames an externally owned table.
     *
     * @throws ForeignTableException
     */
    public function handle(string $table, string $migration, ?string $configKey = null): bool
    {
        $physical = $configKey === null ? TableName::resolve($table) : $table;

        if (! Schema::hasTable($physical)) {
            return false;
        }

        if (TableName::migrationRecorded($migration)) {
            return true;
        }

        $base = $configKey === null ? $table : TableName::base($table);

        throw new ForeignTableException((string) __('capell-core::install.tables.foreign_table', [
            'table' => $physical,
            'migration' => $migration,
            'prefix_key' => 'capell.database.table_prefix',
            'alias_key' => $configKey ?? 'capell.database.table_aliases.' . $base,
        ]));
    }
}
