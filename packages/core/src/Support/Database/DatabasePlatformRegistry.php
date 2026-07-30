<?php

declare(strict_types=1);

namespace Capell\Core\Support\Database;

use Capell\Core\Contracts\Database\DatabasePlatform;
use Capell\Core\Enums\Database\DatabaseFamily;
use Capell\Core\Exceptions\UnsupportedDatabaseDriver;
use Capell\Core\Support\Database\SchemaDialects\MySqlSchemaDialect;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

final class DatabasePlatformRegistry
{
    /** @var array<string, DatabasePlatform> */
    private array $platforms = [];

    /**
     * @param  iterable<DatabasePlatform>  $platforms
     */
    public function __construct(
        iterable $platforms = [],
        private readonly ?DatabaseManager $connections = null,
    ) {
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
}
