<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Upgrade;

use Capell\Core\Data\MigrationRunResult;
use Capell\Core\Support\Migration\CoreSchemaMigrations;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

class RunDatabaseMigrationsAction
{
    use AsFake;
    use AsObject;

    public function handle(bool $dryRun = false): MigrationRunResult
    {
        if ($dryRun) {
            return new MigrationRunResult(0, '[dry-run] would run: php artisan migrate --force --path=packages/core/database/migrations --realpath');
        }

        $this->removePublishedCreateMigrationsForExistingTables();
        $this->markSourceCreateMigrationsLoggedForExistingTables();

        $options = $this->migrationCommandOptions();

        // An empty --path falls back to every registered Laravel migration path.
        if ($options['--path'] === []) {
            return new MigrationRunResult(0, 'No pending core schema migrations.');
        }

        $exit = Artisan::call('migrate', $options);

        return new MigrationRunResult($exit, Artisan::output());
    }

    /**
     * @return array<string, bool|list<string>>
     */
    private function migrationCommandOptions(): array
    {
        return [
            '--force' => true,
            '--path' => array_values(ResolvePendingUpgradeMigrationsAction::run()->core),
            '--realpath' => true,
        ];
    }

    private function removePublishedCreateMigrationsForExistingTables(): void
    {
        $migrationPaths = File::glob(database_path('migrations/*_create_*_table*.php'));

        foreach ($migrationPaths as $migrationPath) {
            $migration = basename((string) $migrationPath, '.php');
            if (! CoreSchemaMigrations::isRedundantPublishedCreate($migration)) {
                continue;
            }

            File::delete($migrationPath);
        }
    }

    private function markSourceCreateMigrationsLoggedForExistingTables(): void
    {
        /** @var Migrator $migrator */
        $migrator = resolve('migrator');
        $repository = $migrator->getRepository();

        try {
            if (! $repository->repositoryExists()) {
                return;
            }
        } catch (Throwable) {
            return;
        }

        $alreadyLogged = array_fill_keys($repository->getRan(), true);
        $batch = $repository->getNextBatchNumber();
        $sourceMigrations = File::glob(CoreSchemaMigrations::path() . '/*_create_*_table*.php') ?: [];

        foreach ($sourceMigrations as $migrationPath) {
            $migration = basename((string) $migrationPath, '.php');

            if (isset($alreadyLogged[$migration])) {
                continue;
            }

            if (! CoreSchemaMigrations::createsExistingTable($migration)) {
                continue;
            }

            $repository->log($migration, $batch);
        }
    }
}
