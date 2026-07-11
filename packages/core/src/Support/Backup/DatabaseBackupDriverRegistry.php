<?php

declare(strict_types=1);

namespace Capell\Core\Support\Backup;

use Capell\Core\Contracts\Backup\DatabaseBackupDriver;
use InvalidArgumentException;
use LogicException;

final class DatabaseBackupDriverRegistry
{
    /** @var array<non-empty-string, DatabaseBackupDriver> */
    private array $drivers = [];

    /**
     * @param  iterable<DatabaseBackupDriver>  $drivers
     */
    public function __construct(iterable $drivers = [])
    {
        foreach ($drivers as $driver) {
            $this->register($driver);
        }
    }

    public function register(DatabaseBackupDriver $driver): self
    {
        foreach ($driver->supportedDrivers() as $name) {
            $name = strtolower(trim($name));

            if ($name === '') {
                throw new LogicException('Database backup drivers must declare a non-empty driver name.');
            }

            if (isset($this->drivers[$name])) {
                throw new LogicException(sprintf('Database backup driver [%s] is already registered.', $name));
            }

            $this->drivers[$name] = $driver;
        }

        return $this;
    }

    public function for(string $driver): DatabaseBackupDriver
    {
        $driver = strtolower(trim($driver));

        return $this->drivers[$driver]
            ?? throw new InvalidArgumentException(sprintf('Unsupported database backup driver [%s].', $driver));
    }
}
