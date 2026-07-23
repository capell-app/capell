<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Preserve NULL as the public meaning of a global permission scope while
 * normalizing it to zero solely for database uniqueness enforcement.
 *
 * MySQL unique indexes treat every NULL as distinct, so the original
 * team-aware indexes permit duplicate global roles and assignments.
 */
return new class extends Migration
{
    private const string SCOPE_COLUMN = 'capell_team_scope_key';

    public function up(): void
    {
        $contracts = $this->uniqueContracts();

        foreach ($contracts as $contract) {
            if (! Schema::hasTable($contract['table'])
                || ! Schema::hasColumn($contract['table'], $contract['teamColumn'])) {
                continue;
            }

            $this->assertGlobalRowsAreUnique($contract['table'], $contract['teamColumn'], $contract['identityColumns']);
        }

        foreach ($contracts as $contract) {
            if (! Schema::hasTable($contract['table'])
                || ! Schema::hasColumn($contract['table'], $contract['teamColumn'])) {
                continue;
            }

            if ($this->hasNormalizedIndex($contract)) {
                continue;
            }

            if (! $this->hasScopeColumn($contract['table'])) {
                Schema::table($contract['table'], function (Blueprint $table) use ($contract): void {
                    $column = $table->unsignedBigInteger(self::SCOPE_COLUMN);
                    $expression = sprintf('COALESCE(%s, 0)', $contract['teamColumn']);

                    DB::connection()->getDriverName() === 'sqlite'
                        ? $column->virtualAs($expression)
                        : $column->storedAs($expression);
                });
            }

            if (! Schema::hasIndex($contract['table'], $contract['normalizedColumns'], 'unique')) {
                Schema::table($contract['table'], function (Blueprint $table) use ($contract): void {
                    $table->unique($contract['normalizedColumns'], $contract['normalizedIndex']);
                });
            }

            if (Schema::hasIndex($contract['table'], $contract['legacyColumns'], 'unique')) {
                Schema::table($contract['table'], function (Blueprint $table) use ($contract): void {
                    $table->dropUnique($contract['legacyIndex']);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->uniqueContracts()) as $contract) {
            if (! Schema::hasTable($contract['table'])
                || ! $this->hasScopeColumn($contract['table'])) {
                continue;
            }

            if (! Schema::hasIndex($contract['table'], $contract['legacyColumns'], 'unique')) {
                Schema::table($contract['table'], function (Blueprint $table) use ($contract): void {
                    $table->unique($contract['legacyColumns'], $contract['legacyIndex']);
                });
            }

            if (Schema::hasIndex($contract['table'], $contract['normalizedIndex'])) {
                Schema::table($contract['table'], function (Blueprint $table) use ($contract): void {
                    $table->dropUnique($contract['normalizedIndex']);
                });
            }

            Schema::table($contract['table'], static function (Blueprint $table): void {
                $table->dropColumn(self::SCOPE_COLUMN);
            });
        }
    }

    /**
     * @param  list<string>  $identityColumns
     */
    private function assertGlobalRowsAreUnique(string $table, string $teamColumn, array $identityColumns): void
    {
        if (DB::table($table)
            ->whereNull($teamColumn)
            ->select($identityColumns)
            ->groupBy($identityColumns)
            ->havingRaw('COUNT(*) > 1')
            ->exists()
        ) {
            throw new RuntimeException(sprintf(
                'Cannot enforce global permission uniqueness while [%s] contains duplicate NULL-team records. Resolve the duplicates, then rerun the migration.',
                $table,
            ));
        }
    }

    /**
     * @return list<array{
     *     table: string,
     *     teamColumn: string,
     *     identityColumns: list<string>,
     *     legacyColumns: list<string>,
     *     normalizedColumns: list<string>,
     *     legacyIndex: string,
     *     normalizedIndex: string
     * }>
     */
    private function uniqueContracts(): array
    {
        $tableNames = config('permission.table_names', [
            'roles' => 'roles',
            'model_has_permissions' => 'model_has_permissions',
            'model_has_roles' => 'model_has_roles',
        ]);
        $teamColumn = $this->columnName(config('permission.column_names.team_foreign_key'), 'team_id');
        $modelMorphKey = $this->columnName(config('permission.column_names.model_morph_key'), 'model_id');
        $rolePivotKey = $this->columnName(config('permission.column_names.role_pivot_key'), 'role_id');
        $permissionPivotKey = $this->columnName(config('permission.column_names.permission_pivot_key'), 'permission_id');

        return [
            $this->contract($tableNames['roles'], $teamColumn, ['name', 'guard_name']),
            $this->contract(
                $tableNames['model_has_roles'],
                $teamColumn,
                [$rolePivotKey, $modelMorphKey, 'model_type'],
                'role',
            ),
            $this->contract(
                $tableNames['model_has_permissions'],
                $teamColumn,
                [$permissionPivotKey, $modelMorphKey, 'model_type'],
                'permission',
            ),
        ];
    }

    /**
     * @param  list<string>  $identityColumns
     * @return array{
     *     table: string,
     *     teamColumn: string,
     *     identityColumns: list<string>,
     *     legacyColumns: list<string>,
     *     normalizedColumns: list<string>,
     *     legacyIndex: string,
     *     normalizedIndex: string
     * }
     */
    private function contract(
        string $table,
        string $teamColumn,
        array $identityColumns,
        ?string $pivot = null,
    ): array {
        $legacyColumns = [$teamColumn, ...$identityColumns];
        $legacyIndex = $pivot === null
            ? $this->indexName($table, $teamColumn . '_name_guard_name_unique')
            : $this->indexName($table, 'team_' . $pivot . '_model_type_unique');

        return [
            'table' => $table,
            'teamColumn' => $teamColumn,
            'identityColumns' => $identityColumns,
            'legacyColumns' => $legacyColumns,
            'normalizedColumns' => [self::SCOPE_COLUMN, ...$identityColumns],
            'legacyIndex' => $legacyIndex,
            'normalizedIndex' => $this->indexName($table, 'capell_global_team_unique'),
        ];
    }

    private function indexName(string $table, string $suffix): string
    {
        return str_replace(['-', '.'], '_', strtolower($table . '_' . $suffix));
    }

    /**
     * @param  array{table: string, legacyColumns: list<string>, normalizedColumns: list<string>, normalizedIndex: string}  $contract
     */
    private function hasNormalizedIndex(array $contract): bool
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return DB::table('sqlite_master')
                ->where('type', 'index')
                ->where('tbl_name', $contract['table'])
                ->where('name', $contract['normalizedIndex'])
                ->exists()
                || ! Schema::hasIndex($contract['table'], $contract['legacyColumns'], 'unique');
        }

        return Schema::hasIndex($contract['table'], $contract['normalizedColumns'], 'unique');
    }

    private function hasScopeColumn(string $table): bool
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return Schema::hasColumn($table, self::SCOPE_COLUMN);
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            throw new RuntimeException(sprintf('Permission table [%s] is not a safe SQL identifier.', $table));
        }

        return collect(DB::select(sprintf('PRAGMA table_xinfo("%s")', $table)))
            ->contains(static fn (object $column): bool => ($column->name ?? null) === self::SCOPE_COLUMN);
    }

    private function columnName(mixed $configured, string $default): string
    {
        $column = is_string($configured) && $configured !== '' ? $configured : $default;

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) !== 1) {
            throw new RuntimeException(sprintf('Permission column [%s] is not a safe SQL identifier.', $column));
        }

        return $column;
    }
};
