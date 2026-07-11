<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Actions\PublishMigrationsAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\PackageData;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

final class PublishPackageMigrationsAction
{
    use AsObject;

    /**
     * @param  Collection<string, PackageData>  $packages
     */
    public function handle(
        Collection $packages,
        ProgressReporter $reporter,
        bool $publishSchema = true,
        bool $publishSettings = true,
        bool $requireMigrationFiles = false,
    ): void {
        if ($packages->isEmpty()) {
            return;
        }

        $reporter->step('Publishing package migrations…');

        $packages->each(function (PackageData $package) use ($publishSchema, $publishSettings, $reporter, $requireMigrationFiles): void {
            if ($publishSchema) {
                $this->publishPackageMigrationType(
                    package: $package,
                    type: 'migrations',
                    directory: 'migrations',
                    reporter: $reporter,
                    requireMigrationFiles: $requireMigrationFiles,
                );
            }

            if ($publishSettings) {
                $this->publishPackageMigrationType(
                    package: $package,
                    type: 'settings',
                    directory: 'settings',
                    reporter: $reporter,
                    requireMigrationFiles: $requireMigrationFiles,
                );
            }
        });
    }

    private function publishPackageMigrationType(
        PackageData $package,
        string $type,
        string $directory,
        ProgressReporter $reporter,
        bool $requireMigrationFiles,
    ): void {
        $migrationsPath = $package->path;

        if ($migrationsPath !== null) {
            $migrationsPath .= '/database/' . $directory;
        }

        if ($migrationsPath === null || ! File::isDirectory($migrationsPath)) {
            if ($requireMigrationFiles) {
                throw new RuntimeException(sprintf(
                    'Package %s declares %s, but database/%s is missing.',
                    $package->name,
                    $type,
                    $directory,
                ));
            }

            return;
        }

        $migrationNames = $this->migrationNames($migrationsPath);

        if ($migrationNames === []) {
            if ($requireMigrationFiles) {
                throw new RuntimeException(sprintf(
                    'Package %s declares %s, but database/%s contains no migration files.',
                    $package->name,
                    $type,
                    $directory,
                ));
            }

            return;
        }

        $result = PublishMigrationsAction::run($type, $migrationNames, $migrationsPath);

        foreach ($result->warnings as $warning) {
            $reporter->report($warning);
        }

        if (! $result->successful()) {
            throw new RuntimeException(sprintf(
                'Failed publishing %s for %s.%s',
                $type,
                $package->name,
                $result->errors !== [] ? "\nOutput: " . implode("\n", $result->errors) : '',
            ));
        }

        foreach ($result->lines as $line) {
            $reporter->report($line);
        }
    }

    /**
     * @return array<int, string>
     */
    private function migrationNames(string $migrationsPath): array
    {
        $migrationPaths = array_merge(
            File::glob($migrationsPath . '/*.php'),
            File::glob($migrationsPath . '/*.php.stub'),
        );

        sort($migrationPaths);

        return array_values(array_unique(array_map(
            fn (string $migrationPath): string => str($migrationPath)
                ->basename()
                ->replaceEnd('.php.stub', '')
                ->replaceEnd('.php', '')
                ->toString(),
            $migrationPaths,
        )));
    }
}
