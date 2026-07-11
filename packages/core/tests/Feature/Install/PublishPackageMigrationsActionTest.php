<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\PublishPackageMigrationsAction;
use Capell\Core\Data\PackageData;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Support\Install\NullProgressReporter;
use Capell\Core\Support\Migration\MigrationFilesystemInterface;
use Capell\Core\Tests\Feature\Commands\Fixtures\FakeMigrationFilesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

it('publishes package migrations without deleting stale published migrations', function (): void {
    $fakeFilesystem = new FakeMigrationFilesystem;
    app()->instance(MigrationFilesystemInterface::class, $fakeFilesystem);

    $packagePath = base_path('tests/fixtures/package-with-migrations');
    $migrationDirectory = $packagePath . '/database/migrations';
    $staleMigrationPath = database_path('migrations/2026_05_01_094441_01_seed_bootstrap_workspace_version.php');

    File::ensureDirectoryExists($migrationDirectory);
    File::ensureDirectoryExists(dirname($staleMigrationPath));
    File::put($migrationDirectory . '/2026_05_10_190900_01_create_publishing_studio_table.php', '<?php declare(strict_types=1);');
    File::put($migrationDirectory . '/2026_05_10_190900_02_seed_bootstrap_workspace_version.php', '<?php declare(strict_types=1);');
    File::put($staleMigrationPath, '<?php declare(strict_types=1);');

    try {
        PublishPackageMigrationsAction::run(collect([
            'capell-app/publishing-studio' => new PackageData(
                name: 'capell-app/publishing-studio',
                type: PackageTypeEnum::Plugin,
                path: $packagePath,
            ),
        ]), new NullProgressReporter);

        expect(File::exists($staleMigrationPath))->toBeTrue();
        expect(collect($fakeFilesystem->calls)->contains(
            fn (array $call): bool => $call[0] === 'copy'
                && str_ends_with((string) $call[1], '2026_05_10_190900_01_create_publishing_studio_table.php'),
        ))->toBeTrue();
        expect(collect($fakeFilesystem->calls)->contains(
            fn (array $call): bool => $call[0] === 'copy'
                && str_ends_with((string) $call[1], '2026_05_10_190900_02_seed_bootstrap_workspace_version.php'),
        ))->toBeTrue();
    } finally {
        File::delete($staleMigrationPath);
        File::deleteDirectory($packagePath);
    }
});

it('publishes package migrations without reconciling repository state', function (): void {
    $fakeFilesystem = new FakeMigrationFilesystem;
    app()->instance(MigrationFilesystemInterface::class, $fakeFilesystem);

    $packagePath = base_path('tests/fixtures/package-with-already-run-migration');
    $migrationDirectory = $packagePath . '/database/migrations';
    $publishedMigrationPath = database_path('migrations/2026_05_02_101112_01_create_existing_package_table.php');
    $ranMigration = '2026_05_01_101112_create_existing_package_table';

    File::ensureDirectoryExists($migrationDirectory);
    File::ensureDirectoryExists(dirname($publishedMigrationPath));
    File::put($migrationDirectory . '/2026_05_10_190901_01_create_existing_package_table.php', '<?php declare(strict_types=1);');
    File::put($publishedMigrationPath, '<?php declare(strict_types=1);');
    DB::table('migrations')->insert([
        'migration' => $ranMigration,
        'batch' => 1,
    ]);

    try {
        PublishPackageMigrationsAction::run(collect([
            'capell-app/existing-package' => new PackageData(
                name: 'capell-app/existing-package',
                type: PackageTypeEnum::Plugin,
                path: $packagePath,
            ),
        ]), new NullProgressReporter);

        expect(File::exists($publishedMigrationPath))->toBeTrue();
        expect(collect($fakeFilesystem->calls)->contains(
            fn (array $call): bool => $call[0] === 'copy'
                && str_ends_with((string) $call[1], '2026_05_10_190901_01_create_existing_package_table.php'),
        ))->toBeTrue();
    } finally {
        DB::table('migrations')->where('migration', $ranMigration)->delete();
        File::delete($publishedMigrationPath);
        File::deleteDirectory($packagePath);
    }
});

it('publishes marketplace package migrations like any other package', function (): void {
    $fakeFilesystem = new FakeMigrationFilesystem;
    app()->instance(MigrationFilesystemInterface::class, $fakeFilesystem);

    $packagePath = base_path('tests/fixtures/marketplace-package-with-migrations');
    $migrationDirectory = $packagePath . '/database/migrations';

    File::ensureDirectoryExists($migrationDirectory);
    File::put($migrationDirectory . '/2026_04_28_000004_create_extension_catalog_entries_table.php', '<?php declare(strict_types=1);');

    try {
        PublishPackageMigrationsAction::run(collect([
            'capell-app/marketplace' => new PackageData(
                name: 'capell-app/marketplace',
                type: PackageTypeEnum::Package,
                path: $packagePath,
            ),
        ]), new NullProgressReporter);

        expect(collect($fakeFilesystem->calls)->contains(
            fn (array $call): bool => $call[0] === 'copy'
                && str_ends_with((string) $call[1], 'create_extension_catalog_entries_table.php'),
        ))->toBeTrue();
    } finally {
        File::deleteDirectory($packagePath);
    }
});

it('can publish package schema migrations without publishing settings migrations', function (): void {
    $fakeFilesystem = new FakeMigrationFilesystem;
    app()->instance(MigrationFilesystemInterface::class, $fakeFilesystem);

    $packagePath = base_path('tests/fixtures/package-with-schema-and-settings');
    $migrationDirectory = $packagePath . '/database/migrations';
    $settingsDirectory = $packagePath . '/database/settings';

    File::ensureDirectoryExists($migrationDirectory);
    File::ensureDirectoryExists($settingsDirectory);
    File::put($migrationDirectory . '/2026_05_10_190902_01_create_example_table.php', '<?php declare(strict_types=1);');
    File::put($settingsDirectory . '/2026_05_10_190902_01_add_example_settings.php', '<?php declare(strict_types=1);');

    try {
        PublishPackageMigrationsAction::run(
            collect([
                'capell-app/example' => new PackageData(
                    name: 'capell-app/example',
                    type: PackageTypeEnum::Plugin,
                    path: $packagePath,
                ),
            ]),
            new NullProgressReporter,
            publishSettings: false,
        );

        expect(collect($fakeFilesystem->calls)->contains(
            fn (array $call): bool => $call[0] === 'copy'
                && str_contains((string) $call[1], '/database/migrations/2026_05_10_190902_01_create_example_table.php')
                && str_contains((string) $call[2], '/database/migrations/'),
        ))->toBeTrue();
        expect(collect($fakeFilesystem->calls)->contains(
            fn (array $call): bool => $call[0] === 'copy'
                && str_contains((string) $call[1], '/database/settings/2026_05_10_190902_01_add_example_settings.php'),
        ))->toBeFalse();
    } finally {
        File::deleteDirectory($packagePath);
    }
});

it('can publish package settings migrations without publishing schema migrations', function (): void {
    $fakeFilesystem = new FakeMigrationFilesystem;
    app()->instance(MigrationFilesystemInterface::class, $fakeFilesystem);

    $packagePath = base_path('tests/fixtures/package-with-settings-only-pass');
    $migrationDirectory = $packagePath . '/database/migrations';
    $settingsDirectory = $packagePath . '/database/settings';

    File::ensureDirectoryExists($migrationDirectory);
    File::ensureDirectoryExists($settingsDirectory);
    File::put($migrationDirectory . '/2026_05_10_190903_01_create_example_table.php', '<?php declare(strict_types=1);');
    File::put($settingsDirectory . '/2026_05_10_190903_01_add_example_settings.php', '<?php declare(strict_types=1);');

    try {
        PublishPackageMigrationsAction::run(
            collect([
                'capell-app/example' => new PackageData(
                    name: 'capell-app/example',
                    type: PackageTypeEnum::Plugin,
                    path: $packagePath,
                ),
            ]),
            new NullProgressReporter,
            publishSchema: false,
        );

        expect(collect($fakeFilesystem->calls)->contains(
            fn (array $call): bool => $call[0] === 'copy'
                && str_contains((string) $call[1], '/database/migrations/2026_05_10_190903_01_create_example_table.php'),
        ))->toBeFalse();
        expect(collect($fakeFilesystem->calls)->contains(
            fn (array $call): bool => $call[0] === 'copy'
                && str_contains((string) $call[1], '/database/settings/2026_05_10_190903_01_add_example_settings.php')
                && str_contains((string) $call[2], '/database/settings/'),
        ))->toBeTrue();
    } finally {
        File::deleteDirectory($packagePath);
    }
});

it('fails loudly when package migration publishing fails', function (): void {
    $fakeFilesystem = new class extends FakeMigrationFilesystem
    {
        public function isWritable(string $path): bool
        {
            $this->calls[] = ['isWritable', $path];

            return false;
        }
    };

    app()->instance(MigrationFilesystemInterface::class, $fakeFilesystem);

    $packagePath = base_path('tests/fixtures/package-with-failing-publish-command');
    $migrationDirectory = $packagePath . '/database/migrations';

    File::ensureDirectoryExists($migrationDirectory);
    File::put($migrationDirectory . '/2026_05_10_190904_01_create_failing_table.php', '<?php declare(strict_types=1);');

    try {
        PublishPackageMigrationsAction::run(collect([
            'capell-app/failing-package' => new PackageData(
                name: 'capell-app/failing-package',
                type: PackageTypeEnum::Plugin,
                path: $packagePath,
            ),
        ]), new NullProgressReporter);
    } finally {
        File::deleteDirectory($packagePath);
    }
})->throws(
    RuntimeException::class,
    'Failed publishing migrations for capell-app/failing-package.',
);

it('fails when a required package migration directory is missing', function (): void {
    $packagePath = base_path('tests/fixtures/package-with-missing-declared-migrations');
    File::ensureDirectoryExists($packagePath);

    try {
        $package = new PackageData(
            name: 'vendor/missing-migrations',
            type: PackageTypeEnum::Plugin,
            path: $packagePath,
        );

        expect(fn (): mixed => PublishPackageMigrationsAction::run(
            collect([$package->name => $package]),
            new NullProgressReporter,
            publishSettings: false,
            requireMigrationFiles: true,
        ))->toThrow(
            RuntimeException::class,
            'Package vendor/missing-migrations declares migrations, but database/migrations is missing.',
        );
    } finally {
        File::deleteDirectory($packagePath);
    }
});

it('fails when a required package migration directory is empty', function (): void {
    $packagePath = base_path('tests/fixtures/package-with-empty-declared-migrations');
    File::ensureDirectoryExists($packagePath . '/database/migrations');

    try {
        $package = new PackageData(
            name: 'vendor/empty-migrations',
            type: PackageTypeEnum::Plugin,
            path: $packagePath,
        );

        expect(fn (): mixed => PublishPackageMigrationsAction::run(
            collect([$package->name => $package]),
            new NullProgressReporter,
            publishSettings: false,
            requireMigrationFiles: true,
        ))->toThrow(
            RuntimeException::class,
            'Package vendor/empty-migrations declares migrations, but database/migrations contains no migration files.',
        );
    } finally {
        File::deleteDirectory($packagePath);
    }
});
