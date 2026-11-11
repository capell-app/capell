<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

/** A first install on SQLite has no database file until the installer creates it, so there is nothing to read yet. */
final class MissingSqliteDatabase
{
    /** The path of the default connection's SQLite file when it does not exist yet, otherwise null. */
    public static function path(): ?string
    {
        $connection = config('database.default');
        $config = is_string($connection) ? config('database.connections.' . $connection) : null;

        if (! is_array($config) || ($config['driver'] ?? null) !== 'sqlite') {
            return null;
        }

        $database = $config['database'] ?? null;

        if (! is_string($database) || $database === '' || $database === ':memory:' || str_starts_with($database, 'file:')) {
            return null;
        }

        if (is_file($database)) {
            return null;
        }

        $relative = base_path($database);

        return is_file($relative) ? null : $database;
    }
}
