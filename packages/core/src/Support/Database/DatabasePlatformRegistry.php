<?php

declare(strict_types=1);

namespace Capell\Core\Support\Database;

use Capell\Core\Contracts\Database\DatabasePlatform;
use Capell\Core\Enums\Database\DatabaseFamily;
use Capell\Core\Exceptions\UnsupportedDatabaseDriver;
use Capell\Core\Support\Database\SchemaDialects\MySqlSchemaDialect;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use LogicException;

final class DatabasePlatformRegistry
{
    /** @var array<string, DatabasePlatform> */
    private array $platforms = [];

    /**
     * @param  iterable<DatabasePlatform>  $platforms
     */
    public function __construct(iterable $platforms = [])
    {
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

    public function for(Connection|Model|EloquentBuilder|QueryBuilder|string|null $context = null): DatabasePlatform
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

        if ($driver !== 'mysql' || $connection === null || ! isset($this->platforms['mariadb'])) {
            return $platform;
        }

        $schema = $platform->schemaDialect();

        return $schema instanceof MySqlSchemaDialect
            && $schema->serverCapabilities($connection)->family === DatabaseFamily::MariaDb
                ? $this->platforms['mariadb']
                : $platform;
    }

    private function connection(Connection|Model|EloquentBuilder|QueryBuilder|string|null $context): ?Connection
    {
        if ($context instanceof Connection) {
            return $context;
        }

        if ($context instanceof Model) {
            return $context->getConnection();
        }

        if ($context instanceof EloquentBuilder) {
            return $context->getModel()->getConnection();
        }

        if ($context instanceof QueryBuilder) {
            return $context->getConnection();
        }

        if (is_string($context) && ! isset($this->platforms[strtolower(trim($context))]) && is_array(config('database.connections.' . $context))) {
            return DB::connection($context);
        }

        return null;
    }
}
