<?php

declare(strict_types=1);

namespace Capell\Core\Support\Database;

use Capell\Core\Contracts\Database\DatabasePlatform;
use Capell\Core\Data\Database\DatabaseFullTextSearch;
use Capell\Core\Data\Database\DatabaseIndexDefinition;
use Capell\Core\Data\Database\DatabaseSearchExpression;
use Capell\Core\Data\Database\SqlFragment;
use Capell\Core\Enums\Database\DatabaseFamily;
use Capell\Core\Exceptions\UnsupportedDatabaseDriver;
use Capell\Core\Support\Database\SchemaDialects\MySqlSchemaDialect;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use LogicException;

final class DatabasePlatformRegistry
{
    /** @var array<string, DatabasePlatform> */
    private array $platforms = [];

    private readonly FullTextIndexCompatibilityCache $fullTextIndexCompatibility;

    /**
     * @param  iterable<DatabasePlatform>  $platforms
     */
    public function __construct(
        iterable $platforms = [],
        ?FullTextIndexCompatibilityCache $fullTextIndexCompatibility = null,
        private readonly ?DatabaseManager $connections = null,
    ) {
        $this->fullTextIndexCompatibility = $fullTextIndexCompatibility ?? new FullTextIndexCompatibilityCache;

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
     * @param  non-empty-list<DatabaseSearchExpression>  $expressions
     */
    public function fullTextSearch(
        Connection|Model $context,
        DatabaseIndexDefinition $index,
        array $expressions,
        string $query,
    ): DatabaseFullTextSearch {
        $connection = $context instanceof Connection
            ? $context
            : $context->getConnection();

        $platform = $this->for($connection);
        $native = $this->hasCompatibleFullTextIndex($platform, $index, $connection);

        return $platform->queryDialect()->fullTextSearch($expressions, $query, $native);
    }

    public function forgetFullTextIndexCompatibility(
        Connection $connection,
        ?DatabaseIndexDefinition $index = null,
    ): void {
        $this->fullTextIndexCompatibility->forget($connection, $index);
    }

    public function flushFullTextIndexCompatibility(): void
    {
        $this->fullTextIndexCompatibility->flush();
    }

    public function for(Connection|Model|string|null $context = null): DatabasePlatform
    {
        if ($context instanceof Connection) {
            return $this->forResolvedConnection($context);
        }

        if ($context instanceof Model) {
            return $this->forResolvedConnection($context->getConnection());
        }

        if ($context === null || trim($context) === '') {
            return $this->forConnection();
        }

        $driver = strtolower(trim($context));

        if (isset($this->platforms[$driver])) {
            return $this->forDriver($driver);
        }

        if (is_array(config('database.connections.' . $context))) {
            return $this->forConnection($context);
        }

        return $this->forDriver($driver);
    }

    public function forDriver(string $driver): DatabasePlatform
    {
        $driver = strtolower(trim($driver));

        return $this->platforms[$driver]
            ?? throw new UnsupportedDatabaseDriver(sprintf('Unsupported database driver [%s].', $driver));
    }

    public function forConnection(?string $connectionName = null): DatabasePlatform
    {
        $connection = $this->connections?->connection($connectionName)
            ?? DB::connection($connectionName);

        return $this->forResolvedConnection($connection);
    }

    public function createFullTextIndex(
        Connection $connection,
        DatabaseIndexDefinition $index,
    ): bool {
        $fragment = $this->forResolvedConnection($connection)
            ->schemaDialect()
            ->fullTextIndex($index);

        if (! $fragment instanceof SqlFragment) {
            return false;
        }

        try {
            return $connection->statement($fragment->sql, $fragment->bindings);
        } finally {
            $this->forgetFullTextIndexCompatibility($connection, $index);
        }
    }

    public function dropFullTextIndex(
        Connection $connection,
        DatabaseIndexDefinition $index,
    ): void {
        try {
            $connection->getSchemaBuilder()->table(
                $index->table,
                static function (Blueprint $table) use ($index): void {
                    $table->dropIndex($index->name);
                },
            );
        } finally {
            $this->forgetFullTextIndexCompatibility($connection, $index);
        }
    }

    private function forResolvedConnection(Connection $connection): DatabasePlatform
    {
        $driver = strtolower($connection->getDriverName());
        $platform = $this->forDriver($driver);

        if ($driver !== 'mysql' || ! isset($this->platforms['mariadb'])) {
            return $platform;
        }

        $schema = $platform->schemaDialect();

        return $schema instanceof MySqlSchemaDialect
            && $schema->serverCapabilities($connection)->family === DatabaseFamily::MariaDb
                ? $this->platforms['mariadb']
                : $platform;
    }

    private function hasCompatibleFullTextIndex(
        DatabasePlatform $platform,
        DatabaseIndexDefinition $index,
        Connection $connection,
    ): bool {
        return $this->fullTextIndexCompatibility->remember(
            $connection,
            $index,
            fn (): bool => $platform->schemaDialect()->hasCompatibleFullTextIndex($index, $connection),
        );
    }
}
