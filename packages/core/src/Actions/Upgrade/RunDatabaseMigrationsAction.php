<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Upgrade;

use Capell\Core\Data\MigrationRunResult;
use Capell\Core\Facades\CapellCore;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
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

        try {
            $exit = Artisan::call('migrate', $this->migrationCommandOptions());
        } catch (FileNotFoundException $fileNotFoundException) {
            throw_unless(str_contains($fileNotFoundException->getMessage(), 'database/settings'), $fileNotFoundException);

            PublishPendingMigrationsAction::run();

            $exit = Artisan::call('migrate', $this->migrationCommandOptions());
        }

        return new MigrationRunResult($exit, Artisan::output());
    }

    /**
     * @return array<string, bool|string>
     */
    private function migrationCommandOptions(): array
    {
        return [
            '--force' => true,
            '--path' => $this->migrationSourcePath(),
            '--realpath' => true,
        ];
    }

    private function migrationSourcePath(): string
    {
        return dirname(__DIR__, 3) . '/database/migrations';
    }

    private function removePublishedCreateMigrationsForExistingTables(): void
    {
        // Assumes Capell create migrations only create the table; side effects belong in later migrations.
        /** @var Migrator $migrator */
        $migrator = resolve('migrator');
        $migrator->getMigrationFiles($migrator->paths());

        $migrationPaths = File::glob(database_path('migrations/*_create_*_table*.php'));

        $managedMigrations = array_fill_keys(array_map(
            $this->migrationNameWithoutTimestamp(...),
            CapellCore::getMigrations(),
        ), true);

        foreach ($migrationPaths as $migrationPath) {
            $migration = basename((string) $migrationPath, '.php');

            if (! isset($managedMigrations[$this->migrationNameWithoutTimestamp($migration)])) {
                continue;
            }

            $table = $this->tableFromCreateMigration($migration);

            if ($table === null) {
                continue;
            }

            if (! Schema::hasTable($table)) {
                continue;
            }

            File::delete($migrationPath);
        }
    }

    private function markSourceCreateMigrationsLoggedForExistingTables(): void
    {
        // Assumes Capell create migrations only create the table; side effects belong in later migrations.
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
        $sourceMigrations = File::glob($this->migrationSourcePath() . '/*_create_*_table*.php') ?: [];

        foreach ($sourceMigrations as $migrationPath) {
            $migration = basename((string) $migrationPath, '.php');

            if (isset($alreadyLogged[$migration])) {
                continue;
            }

            $table = $this->tableFromCreateMigration($migration);
            if ($table === null) {
                continue;
            }

            if (! Schema::hasTable($table)) {
                continue;
            }

            $repository->log($migration, $batch);
        }
    }

    private function migrationNameWithoutTimestamp(string $migration): string
    {
        return preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}(?:_\d{2})?_/', '', $migration) ?? $migration;
    }

    private function tableFromCreateMigration(string $migration): ?string
    {
        if (preg_match('/^(?:\d{4}_\d{2}_\d{2}_\d{6}(?:_\d{2})?_)?create_(.+)_tables?$/', $migration, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
