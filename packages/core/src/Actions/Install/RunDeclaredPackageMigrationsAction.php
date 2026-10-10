<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\PackageData;
use Lorisleiva\Actions\Concerns\AsObject;

final class RunDeclaredPackageMigrationsAction
{
    use AsObject;

    public function handle(PackageData $package, ProgressReporter $reporter): void
    {
        $publishSchema = $package->declaresSchemaMigrations();
        $publishSettings = $package->declaresSettingsMigrations();

        if (! $publishSchema && ! $publishSettings) {
            return;
        }

        PublishPackageMigrationsAction::run(
            packages: collect([$package->name => $package]),
            reporter: $reporter,
            publishSchema: $publishSchema,
            publishSettings: $publishSettings,
            requireMigrationFiles: true,
        );

        if ($publishSchema) {
            RunMigrationsAction::run(
                reporter: $reporter,
                includeSettings: false,
            );
        }

        if ($publishSettings) {
            RunMigrationsAction::run(
                reporter: $reporter,
                includeSettings: true,
                includeSchema: false,
            );
        }
    }
}
