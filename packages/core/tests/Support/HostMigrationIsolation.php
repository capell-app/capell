<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Closure;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;

final class HostMigrationIsolation
{
    public static function setUp(Application $application): Closure
    {
        // Refresh uses suite-owned migrations, so the host must not expose unrelated published copies.
        $originalDatabasePath = $application->databasePath();
        $databasePath = sys_get_temp_dir() . '/capell-upgrade-command-' . bin2hex(random_bytes(8));
        File::ensureDirectoryExists($databasePath . '/migrations');
        $application->useDatabasePath($databasePath);

        return function () use ($application, $originalDatabasePath, $databasePath): void {
            $application->useDatabasePath($originalDatabasePath);
            File::deleteDirectory($databasePath);
        };
    }
}
