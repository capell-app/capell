<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Models\CapellExtension;
use Capell\Core\Models\Site;
use Capell\Core\Support\Install\InstallRunState;
use Illuminate\Database\SQLiteDatabaseDoesNotExistException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use PDOException;
use Throwable;

final class DetectInitialInstallationAction
{
    use AsFake;
    use AsObject;

    public function handle(): bool
    {
        try {
            if (InstallRunState::hasPendingInitialInstallation()) {
                return true;
            }

            $extension = new CapellExtension;
            $schema = $extension->getConnection()->getSchemaBuilder();
            if ($schema->hasTable($extension->getTable())
                && $extension->newQueryWithoutScopes()->whereIn('composer_name', ['capell-app/core', 'capell-app/admin'])->exists()) {
                return false;
            }

            $site = new Site;
            if (! $schema->hasTable($site->getTable())) {
                return true;
            }

            return ! $site->newQueryWithoutScopes()->exists();
        } catch (Throwable $throwable) {
            // An unavailable database is not positive evidence of a first install, but one the
            // installer has yet to create is: nothing can be installed in it.
            return $this->databaseDoesNotExistYet($throwable);
        }
    }

    /**
     * A fresh SQLite project has no database file, and a fresh server project may have no schema,
     * until the installer provisions them.
     */
    private function databaseDoesNotExistYet(Throwable $throwable): bool
    {
        for ($cause = $throwable; $cause instanceof Throwable; $cause = $cause->getPrevious()) {
            if ($cause instanceof SQLiteDatabaseDoesNotExistException) {
                return true;
            }

            if (! $cause instanceof PDOException) {
                continue;
            }

            // MySQL and MariaDB: 1049 unknown database. PostgreSQL: 3D000 invalid catalogue name.
            if (($cause->errorInfo[1] ?? null) === 1049 || in_array('3D000', [$cause->getCode(), $cause->errorInfo[0] ?? null], true)) {
                return true;
            }
        }

        return false;
    }
}
