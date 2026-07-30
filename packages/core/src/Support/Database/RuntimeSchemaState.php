<?php

declare(strict_types=1);

namespace Capell\Core\Support\Database;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class RuntimeSchemaState
{
    /** @var array<string, bool> */
    private array $tables = [];

    /** @var array<string, bool> */
    private array $columns = [];

    public function hasTable(string $table, bool $refresh = false): bool
    {
        if ($refresh || ! array_key_exists($table, $this->tables)) {
            $this->tables[$table] = $this->probeTable($table);
        }

        return $this->tables[$table];
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, bool>
     */
    public function primeTables(array $tables, bool $refresh = false): array
    {
        foreach (array_values(array_unique($tables)) as $table) {
            $this->hasTable($table, $refresh);
        }

        return $this->tables;
    }

    public function hasColumn(string $table, string $column, bool $refresh = false): bool
    {
        $key = $this->columnKey($table, $column);

        if ($refresh || ! array_key_exists($key, $this->columns)) {
            $this->columns[$key] = $this->probeColumn($table, $column);
        }

        return $this->columns[$key];
    }

    public function refreshTable(string $table): bool
    {
        return $this->hasTable($table, refresh: true);
    }

    public function refreshColumn(string $table, string $column): bool
    {
        return $this->hasColumn($table, $column, refresh: true);
    }

    public function flush(): void
    {
        $this->tables = [];
        $this->columns = [];
    }

    public function forgetTable(string $table): void
    {
        unset($this->tables[$table]);

        foreach (array_keys($this->columns) as $key) {
            if (str_starts_with($key, $table . '.')) {
                unset($this->columns[$key]);
            }
        }
    }

    public function forgetColumn(string $table, string $column): void
    {
        unset($this->columns[$this->columnKey($table, $column)]);
    }

    private function probeTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable $throwable) {
            $this->reportProbeFailure(['table' => $table], $throwable);

            return false;
        }
    }

    private function probeColumn(string $table, string $column): bool
    {
        try {
            return Schema::hasColumn($table, $column);
        } catch (Throwable $throwable) {
            $this->reportProbeFailure(['table' => $table, 'column' => $column], $throwable);

            return false;
        }
    }

    /**
     * A failed probe still reports "absent", because every caller uses this to
     * decide whether it may touch a table at all: reporting "present" after a
     * failed lookup would send them into a query that cannot succeed, and
     * throwing would turn an unavailable database into a fatal boot error for
     * surfaces that are meant to degrade (installer, dashboard, health reports).
     *
     * The two cases are indistinguishable in the return value, so they are made
     * distinguishable in the log: absence is silent, a failed probe is not.
     *
     * @param  array<string, string>  $target
     */
    private function reportProbeFailure(array $target, Throwable $throwable): void
    {
        Log::warning('Capell runtime schema probe failed; treating the schema as absent.', [
            ...$target,
            'exception' => $throwable::class,
            'reason' => $throwable->getMessage(),
        ]);
    }

    private function columnKey(string $table, string $column): string
    {
        return sprintf('%s.%s', $table, $column);
    }
}
