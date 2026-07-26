<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Enums\Database\DatabaseProvisioningResult;
use Capell\Core\Exceptions\UnsupportedDatabaseDriver;
use Capell\Core\Facades\CapellDatabase;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class EnsureDatabaseExistsAction
{
    use AsFake;
    use AsObject;

    public function handle(ProgressReporter $reporter): void
    {
        $connectionName = config('database.default');
        $config = config('database.connections.' . $connectionName);

        if (! is_string($connectionName) || ! is_array($config)) {
            return;
        }

        $driver = $config['driver'] ?? null;

        if (! is_string($driver) || $driver === '') {
            return;
        }

        try {
            $platform = CapellDatabase::for($driver);
        } catch (UnsupportedDatabaseDriver) {
            return;
        }

        $result = $platform->provisioner()?->provision($connectionName, $config);

        if ($result === null || ! $result->isReady()) {
            return;
        }

        $reporter->report($platform->family()->value === 'sqlite' && $result === DatabaseProvisioningResult::Created
            ? '✓ SQLite database created'
            : '✓ Database is ready');
    }
}
