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
    ): void {
        if ($packages->isEmpty()) {
            return;
        }

        $reporter->step('Publishing package migrations…');

        $packages->each(function (PackageData $package) use ($publishSchema, $publishSettings, $reporter): void {
            if ($publishSchema) {
                $this->publishPackageMigrationType(
                    package: $package,
                    type: 'migrations',
                    directory: 'migrations',
                    reporter: $reporter,
                );
            }

            if ($publishSettings) {
                $this->publishPackageMigrationType(
                    package: $package,
                    type: 'settings',
                    directory: 'settings',
                    reporter: $reporter,
                );
            }
        });
    }

    private function publishPackageMigrationType(
        PackageData $package,
        string $type,
        string $directory,
        ProgressReporter $reporter,
    ): void {
        $migrationsPath = $package->path;

        if ($migrationsPath !== null) {
            $migrationsPath .= '/database/' . $directory;
        }

        if ($migrationsPath === null || ! File::isDirectory($migrationsPath)) {
            return;
        }

        $migrationNames = $this->migrationNames($migrationsPath);

        if ($migrationNames === []) {
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
