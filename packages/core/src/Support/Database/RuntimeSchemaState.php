<?php

declare(strict_types=1);

namespace Capell\Core\Support\Database;

use Capell\Core\Enums\SchemaProbeResult;
use Capell\Core\Exceptions\SchemaProbeFailedException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class RuntimeSchemaState
{
    /** @var array<string, SchemaProbeResult> */
    private array $tables = [];

    /** @var array<string, SchemaProbeResult> */
    private array $columns = [];

    /** @var array<string, Throwable> */
    private array $tableFailures = [];

    /** @var array<string, Throwable> */
    private array $columnFailures = [];

    public function hasTable(string $table, bool $refresh = false): bool
    {
        return $this->tableResult($table, $refresh)->exists();
    }

    public function tableResult(string $table, bool $refresh = false): SchemaProbeResult
    {
        if ($refresh || ! array_key_exists($table, $this->tables)) {
            $this->tables[$table] = $this->probeTable($table);
        }

        return $this->tables[$table];
    }

    /**
     * @throws SchemaProbeFailedException
     */
    public function hasTableOrFail(string $table, bool $refresh = false): bool
    {
        $result = $this->tableResult($table, $refresh);

        if ($result === SchemaProbeResult::Failed) {
            throw SchemaProbeFailedException::forTable($table, $this->tableFailures[$table]);
        }

        return $result->exists();
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

        return array_map(
            static fn (SchemaProbeResult $result): bool => $result->exists(),
            $this->tables,
        );
    }

    public function hasColumn(string $table, string $column, bool $refresh = false): bool
    {
        return $this->columnResult($table, $column, $refresh)->exists();
    }

    public function columnResult(string $table, string $column, bool $refresh = false): SchemaProbeResult
    {
        $key = $this->columnKey($table, $column);

        if ($refresh || ! array_key_exists($key, $this->columns)) {
            $this->columns[$key] = $this->probeColumn($table, $column);
        }

        return $this->columns[$key];
    }

    /**
     * @throws SchemaProbeFailedException
     */
    public function hasColumnOrFail(string $table, string $column, bool $refresh = false): bool
    {
        $result = $this->columnResult($table, $column, $refresh);

        if ($result === SchemaProbeResult::Failed) {
            throw SchemaProbeFailedException::forColumn($table, $column, $this->columnFailures[$this->columnKey($table, $column)]);
        }

        return $result->exists();
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
        $this->tableFailures = [];
        $this->columnFailures = [];
    }

    public function forgetTable(string $table): void
    {
        unset($this->tables[$table], $this->tableFailures[$table]);

        foreach (array_keys($this->columns) as $key) {
            if (str_starts_with($key, $table . '.')) {
                unset($this->columns[$key], $this->columnFailures[$key]);

            }
        }
    }

    public function forgetColumn(string $table, string $column): void
    {
        $key = $this->columnKey($table, $column);

        unset($this->columns[$key], $this->columnFailures[$key]);
    }

    private function probeTable(string $table): SchemaProbeResult
    {
        try {
            unset($this->tableFailures[$table]);

            return Schema::hasTable($table)
                ? SchemaProbeResult::Present
                : SchemaProbeResult::Absent;
        } catch (Throwable $throwable) {
            $this->tableFailures[$table] = $throwable;
            $this->reportProbeFailure(['table' => $table], $throwable);

            return SchemaProbeResult::Failed;
        }
    }

    private function probeColumn(string $table, string $column): SchemaProbeResult
    {
        $key = $this->columnKey($table, $column);

        try {
            unset($this->columnFailures[$key]);

            return Schema::hasColumn($table, $column)
                ? SchemaProbeResult::Present
                : SchemaProbeResult::Absent;
        } catch (Throwable $throwable) {
            $this->columnFailures[$key] = $throwable;
            $this->reportProbeFailure(['table' => $table, 'column' => $column], $throwable);

            return SchemaProbeResult::Failed;
        }
    }

    /**
     * Failed probes are memoized separately from genuine absence so strict
     * callers can surface the original failure without repeating the probe,
     * while boolean callers retain fail-closed degradation and bounded logging.
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
