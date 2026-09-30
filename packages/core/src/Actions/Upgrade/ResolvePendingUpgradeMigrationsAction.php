<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Upgrade;

use Capell\Core\Data\PendingUpgradeMigrations;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Migration\CoreSchemaMigrations;
use Capell\Core\Support\Migration\MigrationFileScanner;
use Illuminate\Database\Migrations\Migrator;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ResolvePendingUpgradeMigrationsAction
{
    use AsFake;
    use AsObject;

    public function handle(): PendingUpgradeMigrations
    {
        /** @var Migrator $migrator */
        $migrator = resolve('migrator');
        $repository = $migrator->getRepository();
        $ran = $repository->repositoryExists() ? array_fill_keys($repository->getRan(), true) : [];
        $core = $migrator->getMigrationFiles(CoreSchemaMigrations::path());
        $published = $migrator->getMigrationFiles(database_path('migrations'));

        // Model publication without executing extension code or writing files.
        // Only installed package sources will be copied into the host directory.
        foreach (CapellCore::getInstalledPackages() as $package) {
            if ($package->path === null) {
                continue;
            }

            foreach (MigrationFileScanner::names($package->path . '/database/migrations') as $migration) {
                // Match Laravel's directory discovery before using explicit file paths.
                if (! str_contains($migration, '_')) {
                    continue;
                }

                $published[$migration] = database_path('migrations/' . $migration . '.php');
            }
        }

        // Core runs first; its published copies share the same ledger names.
        $published = array_diff_key($published, $core, $ran);
        $core = array_diff_key($core, $ran);

        foreach (array_keys($core) as $migration) {
            if (CoreSchemaMigrations::createsExistingTable($migration)) {
                unset($core[$migration]);
            }
        }

        foreach (array_keys($published) as $migration) {
            if (CoreSchemaMigrations::isRedundantPublishedCreate($migration, array_keys($core))) {
                unset($published[$migration]);
            }
        }

        ksort($core);
        ksort($published);

        return new PendingUpgradeMigrations($core, $published);
    }
}
