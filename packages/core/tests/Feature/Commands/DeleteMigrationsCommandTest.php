<?php

declare(strict_types=1);

use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Migration\MigrationFilesystemInterface;
use Capell\Core\Tests\Support\Stubs\FakeMigrationFilesystem;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->temporaryBasePath = sys_get_temp_dir() . '/capell-delete-migrations-test-' . uniqid();
    $this->packagePath = $this->temporaryBasePath . '/packages/example-extension';

    File::ensureDirectoryExists($this->temporaryBasePath . '/database/migrations');
    File::ensureDirectoryExists($this->packagePath . '/database/migrations');
    $this->app->setBasePath($this->temporaryBasePath);
});

afterEach(function (): void {
    if (is_dir($this->temporaryBasePath)) {
        File::deleteDirectory($this->temporaryBasePath);
    }
});

it('deletes published migration files for an extension', function (): void {
    $sourceMigration = $this->packagePath . '/database/migrations/2026_05_10_190832_01_create_example_table.php';
    $sourceStub = $this->packagePath . '/database/migrations/2026_05_10_190832_02_create_second_table.php.stub';
    $publishedMigration = database_path('migrations/2026_05_10_190832_01_create_example_table.php');
    $publishedStubMigration = database_path('migrations/2026_05_10_190832_02_create_second_table.php');

    $filesystem = new FakeMigrationFilesystem([
        'glob' => [
            $this->packagePath . '/database/migrations/*.php' => [$sourceMigration],
            $this->packagePath . '/database/migrations/*.php.stub' => [$sourceStub],
        ],
        'fileExists' => [
            $publishedMigration => true,
            $publishedStubMigration => true,
        ],
    ]);

    app()->instance(MigrationFilesystemInterface::class, $filesystem);
    CapellCore::registerPackage('vendor/example-extension', PackageTypeEnum::Plugin, path: $this->packagePath, version: '1.0.0');

    artisanCommand('capell:delete-migrations', [
        'extension' => 'vendor/example-extension',
    ])->assertExitCode(0)
        ->expectsOutputToContain('Delete report: 2 deleted, 0 blocked, 0 skipped.');

    expect($filesystem->calls)->toContain(['delete', $publishedMigration])
        ->and($filesystem->calls)->toContain(['delete', $publishedStubMigration]);
});

it('deletes published migration files for all registered packages', function (): void {
    $firstPackagePath = $this->temporaryBasePath . '/packages/first-extension';
    $secondPackagePath = $this->temporaryBasePath . '/packages/second-extension';
    $firstSourceMigration = $firstPackagePath . '/database/migrations/2026_05_10_190832_01_create_first_table.php';
    $secondSourceMigration = $secondPackagePath . '/database/migrations/2026_05_10_190832_02_create_second_table.php';
    $firstPublishedMigration = database_path('migrations/2026_05_10_190832_01_create_first_table.php');
    $secondPublishedMigration = database_path('migrations/2026_05_10_190832_02_create_second_table.php');

    File::ensureDirectoryExists($firstPackagePath . '/database/migrations');
    File::ensureDirectoryExists($secondPackagePath . '/database/migrations');

    $filesystem = new FakeMigrationFilesystem([
        'glob' => [
            $firstPackagePath . '/database/migrations/*.php' => [$firstSourceMigration],
            $firstPackagePath . '/database/migrations/*.php.stub' => [],
            $secondPackagePath . '/database/migrations/*.php' => [$secondSourceMigration],
            $secondPackagePath . '/database/migrations/*.php.stub' => [],
        ],
        'fileExists' => [
            $firstPublishedMigration => true,
            $secondPublishedMigration => true,
        ],
    ]);

    app()->instance(MigrationFilesystemInterface::class, $filesystem);
    CapellCore::registerPackage('vendor/first-extension', PackageTypeEnum::Plugin, path: $firstPackagePath, version: '1.0.0');
    CapellCore::registerPackage('vendor/second-extension', PackageTypeEnum::Plugin, path: $secondPackagePath, version: '1.0.0');

    artisanCommand('capell:delete-migrations', [
        '--all' => true,
    ])->assertExitCode(0)
        ->expectsOutputToContain('Delete report: 2 deleted, 0 blocked, 0 skipped.');

    expect($filesystem->calls)->toContain(['delete', $firstPublishedMigration])
        ->and($filesystem->calls)->toContain(['delete', $secondPublishedMigration]);
});

it('requires an extension name or all option', function (): void {
    app()->instance(MigrationFilesystemInterface::class, new FakeMigrationFilesystem);

    artisanCommand('capell:delete-migrations')
        ->assertExitCode(1)
        ->expectsOutputToContain('Pass an extension package name or use --all.');
});

it('fails when the extension is unknown', function (): void {
    app()->instance(MigrationFilesystemInterface::class, new FakeMigrationFilesystem);

    artisanCommand('capell:delete-migrations', [
        'extension' => 'vendor/missing-extension',
    ])->assertExitCode(1)
        ->expectsOutputToContain("Extension 'vendor/missing-extension' is unknown.");
});
