<?php

declare(strict_types=1);

namespace Capell\Core\Support\Database;

use Capell\Core\Enums\SchemaProbeResult;
use Capell\Core\Exceptions\SchemaProbeFailedException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class RuntimeSchemaState
{
    private const int SHARED_LISTING_TTL_SECONDS = 3600;

    /** @var array<string, SchemaProbeResult> */
    private array $tables = [];

    /** @var array<string, SchemaProbeResult> */
    private array $columns = [];

    /** @var array<string, Throwable> */
    private array $tableFailures = [];

    /** @var array<string, Throwable> */
    private array $columnFailures = [];

    /**
     * The connection's tables, listed once and keyed by exact name. Null
     * until first needed; false when the listing could not be read, so every
     * table is then probed on its own.
     *
     * @var array<string, true>|false|null
     */
    private array|false|null $tableListing = null;

    public function hasTable(string $table, bool $refresh = false): bool
    {
        return $this->tableResult($table, $refresh)->exists();
    }

    public function tableResult(string $table, bool $refresh = false): SchemaProbeResult
    {
        if ($refresh || ! array_key_exists($table, $this->tables)) {
            $this->tables[$table] = $this->probeTable($table, $refresh);
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
        $this->forgetSharedTableListing();
        $this->tableListing = null;

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
        $this->tableListing = null;
        $this->forgetSharedTableListing();
    }

    public function forgetTable(string $table): void
    {
        unset($this->tables[$table], $this->tableFailures[$table]);
        $this->tableListing = null;
        $this->forgetSharedTableListing();

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

    /**
     * One listing of the connection's tables answers every table it names, so
     * the packages that each gate on their own table share a single query.
     * Only presence is taken from it: a table missing from the listing is
     * probed on its own, so one created after the listing was read is still
     * found. A refresh always probes the table itself.
     */
    private function probeTable(string $table, bool $refresh = false): SchemaProbeResult
    {
        if (! $refresh && $this->listedTable($table)) {
            unset($this->tableFailures[$table]);

            return SchemaProbeResult::Present;
        }

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

    private function listedTable(string $table): bool
    {
        // A schema-qualified name names another schema than the one listed.
        if (str_contains($table, '.')) {
            return false;
        }

        if ($this->tableListing === null) {
            $this->tableListing = $this->readTableListing();
        }

        if ($this->tableListing === false) {
            return false;
        }

        try {
            $prefix = Schema::getConnection()->getTablePrefix();
        } catch (Throwable) {
            return false;
        }

        return isset($this->tableListing[$prefix . $table]);
    }

    /**
     * Lists the tables of the schema Schema::hasTable() looks in. Names are
     * matched exactly, so a spelling the database might fold differently is
     * probed directly instead. A listing that cannot be read is not a probe
     * failure: each table is then probed directly, which reports its own
     * failure.
     *
     * @return array<string, true>|false
     */
    private function readTableListing(): array|false
    {
        try {
            $names = $this->sharedTableNames();
        } catch (Throwable $throwable) {
            // Reported once for the request: every table is probed on its own from here, which
            // costs a query each, and the listing is not retried until the schema is forgotten.
            Log::warning('Capell runtime schema listing failed; probing each table on its own.', [
                'exception' => $throwable::class,
                'reason' => $throwable->getMessage(),
            ]);

            return false;
        }

        $listing = [];

        foreach ($names as $name) {
            if (is_string($name)) {
                $listing[$name] = true;
            }
        }

        return $listing;
    }

    /**
     * The table names are shared across requests so a warm request issues no
     * information_schema query. The entry is dropped whenever migrations run
     * or the schema is reported changed, and expires on its own as a backstop
     * for tables dropped outside a migration.
     *
     * @return list<mixed>
     */
    private function sharedTableNames(): array
    {
        $key = $this->sharedListingKey();

        try {
            $cached = Cache::get($key);

            if (is_array($cached)) {
                return array_values($cached);
            }
        } catch (Throwable) {
            // An unreadable cache falls back to reading the schema itself.
        }

        $names = array_values(Schema::getTableListing(Schema::getCurrentSchemaName(), schemaQualified: false));

        try {
            Cache::put($key, $names, self::SHARED_LISTING_TTL_SECONDS);
        } catch (Throwable) {
            // A cache that cannot store the listing only costs the next request a probe.
        }

        return $names;
    }

    private function forgetSharedTableListing(): void
    {
        try {
            Cache::forget($this->sharedListingKey());
        } catch (Throwable) {
            // Nothing cached can be read back either.
        }
    }

    private function sharedListingKey(): string
    {
        try {
            $connection = Schema::getConnection();

            return sprintf(
                'capell:schema:tables:%s:%s:%s',
                $connection->getName(),
                (string) $connection->getDatabaseName(),
                $connection->getTablePrefix(),
            );
        } catch (Throwable) {
            return 'capell:schema:tables:unknown';
        }
    }

    private function columnKey(string $table, string $column): string
    {
        return sprintf('%s.%s', $table, $column);
    }
}
