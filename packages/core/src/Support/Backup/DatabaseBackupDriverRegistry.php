<?php

declare(strict_types=1);

namespace Capell\Core\Support\Backup;

use Capell\Core\Contracts\Backup\DatabaseBackupDriver;
use Capell\Core\Support\Registries\AbstractKeyedRegistry;
use InvalidArgumentException;
use LogicException;

/** @extends AbstractKeyedRegistry<DatabaseBackupDriver> */
final class DatabaseBackupDriverRegistry extends AbstractKeyedRegistry
{
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

            throw_if($name === '', LogicException::class, 'Database backup drivers must declare a non-empty driver name.');

            if ($this->hasItem($name)) {
                throw new LogicException(sprintf('Database backup driver [%s] is already registered.', $name));
            }

            $this->setItem($name, $driver);
        }

        return $this;
    }

    public function for(string $driver): DatabaseBackupDriver
    {
        $driver = strtolower(trim($driver));

        return $this->getItem($driver)
            ?? throw new InvalidArgumentException(sprintf('Unsupported database backup driver [%s].', $driver));
    }
}
