<?php

declare(strict_types=1);

namespace Capell\Core\Support\Database;

use Capell\Core\Contracts\Database\DatabasePlatform;
use Capell\Core\Data\Database\DatabaseFullTextSearch;
use Capell\Core\Data\Database\DatabaseIndexDefinition;
use Capell\Core\Data\Database\SqlFragment;
use Capell\Core\Enums\Database\DatabaseFamily;
use Capell\Core\Exceptions\UnsupportedDatabaseDriver;
use Capell\Core\Support\Database\SchemaDialects\MySqlSchemaDialect;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;
use WeakMap;

final class DatabasePlatformRegistry
{
    /** @var array<string, DatabasePlatform> */
    private array $platforms = [];

    /** @var WeakMap<Connection, array<string, bool>> */
    private WeakMap $fullTextIndexCompatibility;

    /**
     * @param  iterable<DatabasePlatform>  $platforms
     */
    public function __construct(iterable $platforms = [])
    {
        $this->fullTextIndexCompatibility = new WeakMap;

        foreach ($platforms as $platform) {
            $this->register($platform);
        }
    }

    public function register(DatabasePlatform $platform): self
    {
        foreach ($platform->drivers() as $driver) {
            $driver = strtolower(trim($driver));
            throw_if($driver === '', LogicException::class, 'Database platforms must declare a non-empty driver name.');
            throw_if(isset($this->platforms[$driver]), LogicException::class, sprintf('Database driver [%s] is already registered.', $driver));

            $this->platforms[$driver] = $platform;
        }

        return $this;
    }

    /**
     * @param  non-empty-list<SqlFragment>  $expressions
     */
    public function fullTextSearch(
        Connection|Model $context,
        DatabaseIndexDefinition $index,
        array $expressions,
        string $query,
    ): DatabaseFullTextSearch {
        $connection = $this->connection($context);
        throw_unless($connection instanceof Connection, LogicException::class, 'Full-text search requires a database connection.');

        $platform = $this->for($connection);
        $native = $this->hasCompatibleFullTextIndex($platform, $index, $connection);

        return $platform->queryDialect()->fullTextSearch($expressions, $query, $native);
    }

    public function forgetFullTextIndexCompatibility(
        Connection $connection,
        ?DatabaseIndexDefinition $index = null,
    ): void {
        if (! isset($this->fullTextIndexCompatibility[$connection])) {
            return;
        }

        if (! $index instanceof DatabaseIndexDefinition) {
            unset($this->fullTextIndexCompatibility[$connection]);

            return;
        }

        $compatibility = $this->fullTextIndexCompatibility[$connection];
        unset($compatibility[$this->fullTextIndexKey($connection, $index)]);

        if ($compatibility === []) {
            unset($this->fullTextIndexCompatibility[$connection]);

            return;
        }

        $this->fullTextIndexCompatibility[$connection] = $compatibility;
    }

    public function flushFullTextIndexCompatibility(): void
    {
        $this->fullTextIndexCompatibility = new WeakMap;
    }

    public function for(Connection|Model|string|null $context = null): DatabasePlatform
    {
        $connection = $this->connection($context);
        $driver = $connection?->getDriverName()
            ?? strtolower(trim(is_string($context) ? $context : ''));

        if ($driver === '') {
            $connection = DB::connection();
            $driver = $connection->getDriverName();
        }

        $platform = $this->platforms[$driver]
            ?? throw new UnsupportedDatabaseDriver(sprintf('Unsupported database driver [%s].', $driver));

        if ($driver !== 'mysql' || ! $connection instanceof Connection || ! isset($this->platforms['mariadb'])) {
            return $platform;
        }

        $schema = $platform->schemaDialect();

        return $schema instanceof MySqlSchemaDialect
            && $schema->serverCapabilities($connection)->family === DatabaseFamily::MariaDb
                ? $this->platforms['mariadb']
                : $platform;
    }

    private function connection(Connection|Model|string|null $context): ?Connection
    {
        if ($context instanceof Connection) {
            return $context;
        }

        if ($context instanceof Model) {
            return $context->getConnection();
        }

        if (is_string($context) && ! isset($this->platforms[strtolower(trim($context))]) && is_array(config('database.connections.' . $context))) {
            return DB::connection($context);
        }

        return null;
    }

    private function hasCompatibleFullTextIndex(
        DatabasePlatform $platform,
        DatabaseIndexDefinition $index,
        Connection $connection,
    ): bool {
        $key = $this->fullTextIndexKey($connection, $index);
        $compatibility = $this->fullTextIndexCompatibility[$connection] ?? [];

        if (! array_key_exists($key, $compatibility)) {
            $compatibility[$key] = $platform->schemaDialect()->hasCompatibleFullTextIndex($index, $connection);
            $this->fullTextIndexCompatibility[$connection] = $compatibility;
        }

        return $compatibility[$key];
    }

    private function fullTextIndexKey(Connection $connection, DatabaseIndexDefinition $index): string
    {
        $prefixLengths = $index->prefixLengths;
        ksort($prefixLengths);

        return hash('sha256', serialize([
            'database' => $connection->getDatabaseName(),
            'table' => $index->table,
            'name' => $index->name,
            'columns' => $index->columns,
            'prefix_lengths' => $prefixLengths,
            'unique' => $index->unique,
        ]));
    }
}
