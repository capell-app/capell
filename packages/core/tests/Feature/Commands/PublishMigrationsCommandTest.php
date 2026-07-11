<?php

declare(strict_types=1);

use Capell\Core\Actions\PublishMigrationsAction;
use Capell\Core\Support\Dataset\DatasetPublisher;
use Capell\Core\Support\Migration\MigrationFilesystemInterface;
use Capell\Core\Tests\Support\Stubs\FakeMigrationFilesystem;
use Capell\Core\Tests\Support\Stubs\StubDatasetPublisher;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->temporaryBasePath = sys_get_temp_dir() . '/capell-publish-migrations-test-' . uniqid();

    File::ensureDirectoryExists($this->temporaryBasePath . '/database/migrations');
    $this->app->setBasePath($this->temporaryBasePath);
});

afterEach(function (): void {
    if (is_dir($this->temporaryBasePath)) {
        File::deleteDirectory($this->temporaryBasePath);
    }
});

it('copies timestamped migration files without renaming them', function (): void {
    $itemName = '2026_05_10_190832_01_create_test_table';
    $sourceDirectory = '/fake/path';
    $sourceFile = $sourceDirectory . DIRECTORY_SEPARATOR . $itemName . '.php';
    $migrationsDirectory = database_path('migrations');

    $filesystem = new FakeMigrationFilesystem([
        'fileExists' => [
            $sourceFile => true,
        ],
        'isDir' => [
            $sourceDirectory . DIRECTORY_SEPARATOR => true,
            $migrationsDirectory => true,
        ],
    ]);

    app()->instance(MigrationFilesystemInterface::class, $filesystem);
    app()->instance(DatasetPublisher::class, new StubDatasetPublisher);

    artisanCommand('capell:publish-migrations', [
        '--items' => [$itemName],
        '--path' => $sourceDirectory,
    ])->assertExitCode(0)
        ->expectsOutputToContain('Publish report: 1 applied, 0 blocked.');

    expect(collect($filesystem->calls)->contains(
        fn (array $call): bool => $call === [
            'copy',
            $sourceFile,
            $migrationsDirectory . DIRECTORY_SEPARATOR . $itemName . '.php',
        ],
    ))->toBeTrue();
});

it('publishes migrations through the reusable action without artisan command registration', function (): void {
    $itemName = '2026_05_10_190832_07_create_action_table';
    $sourceDirectory = '/fake/action-path';
    $sourceFile = $sourceDirectory . DIRECTORY_SEPARATOR . $itemName . '.php';
    $migrationsDirectory = database_path('migrations');

    $filesystem = new FakeMigrationFilesystem([
        'fileExists' => [
            $sourceFile => true,
        ],
        'isDir' => [
            $sourceDirectory . DIRECTORY_SEPARATOR => true,
            $migrationsDirectory => true,
        ],
    ]);

    app()->instance(MigrationFilesystemInterface::class, $filesystem);
    app()->instance(DatasetPublisher::class, new StubDatasetPublisher);

    $result = PublishMigrationsAction::run('migrations', [$itemName], $sourceDirectory);

    expect($result->successful())->toBeTrue()
        ->and($result->applied)->toBe(1)
        ->and(collect($filesystem->calls)->contains(
            fn (array $call): bool => $call === [
                'copy',
                $sourceFile,
                $migrationsDirectory . DIRECTORY_SEPARATOR . $itemName . '.php',
            ],
        ))->toBeTrue();
});

it('copies full migration paths without renaming them', function (): void {
    $sourceFile = '/fake/vendor/package/database/migrations/2026_05_10_190832_02_create_second_table.php';
    $migrationsDirectory = database_path('migrations');

    $filesystem = new FakeMigrationFilesystem([
        'fileExists' => [
            $sourceFile => true,
        ],
        'isDir' => [
            $migrationsDirectory => true,
        ],
    ]);

    app()->instance(MigrationFilesystemInterface::class, $filesystem);
    app()->instance(DatasetPublisher::class, new StubDatasetPublisher);

    artisanCommand('capell:publish-migrations', [
        '--items' => [$sourceFile],
    ])->assertExitCode(0);

    expect(collect($filesystem->calls)->contains(
        fn (array $call): bool => $call === [
            'copy',
            $sourceFile,
            $migrationsDirectory . DIRECTORY_SEPARATOR . basename($sourceFile),
        ],
    ))->toBeTrue();
});

it('publishes php stubs to php files with the same timestamped base name', function (): void {
    $itemName = '2026_05_10_190832_03_create_stub_table';
    $sourceDirectory = '/fake/path';
    $sourceFile = $sourceDirectory . DIRECTORY_SEPARATOR . $itemName . '.php';
    $stubFile = $sourceFile . '.stub';
    $migrationsDirectory = database_path('migrations');

    $filesystem = new FakeMigrationFilesystem([
        'fileExists' => [
            $sourceFile => false,
            $stubFile => true,
        ],
        'isDir' => [
            $sourceDirectory . DIRECTORY_SEPARATOR => true,
            $migrationsDirectory => true,
        ],
    ]);

    app()->instance(MigrationFilesystemInterface::class, $filesystem);
    app()->instance(DatasetPublisher::class, new StubDatasetPublisher);

    artisanCommand('capell:publish-migrations', [
        '--items' => [$itemName],
        '--path' => $sourceDirectory,
    ])->assertExitCode(0);

    expect(collect($filesystem->calls)->contains(
        fn (array $call): bool => $call === [
            'copy',
            $stubFile,
            $migrationsDirectory . DIRECTORY_SEPARATOR . $itemName . '.php',
        ],
    ))->toBeTrue();
});

it('creates migrations directory if missing', function (): void {
    $itemName = '2026_05_10_190832_04_create_directory_table';
    $sourceDirectory = '/fake/path';
    $sourceFile = $sourceDirectory . DIRECTORY_SEPARATOR . $itemName . '.php';
    $migrationsDirectory = database_path('migrations');

    $filesystem = new FakeMigrationFilesystem([
        'fileExists' => [
            $sourceFile => true,
        ],
        'isDir' => [
            $sourceDirectory . DIRECTORY_SEPARATOR => true,
            $migrationsDirectory => false,
        ],
    ]);

    app()->instance(MigrationFilesystemInterface::class, $filesystem);
    app()->instance(DatasetPublisher::class, new StubDatasetPublisher);

    artisanCommand('capell:publish-migrations', [
        '--items' => [$itemName],
        '--path' => $sourceDirectory,
    ])->assertExitCode(0);

    expect(collect($filesystem->calls)->contains(
        fn (array $call): bool => $call === ['makeDir', $migrationsDirectory],
    ))->toBeTrue();
});

it('fails clearly when the destination directory is not writable', function (): void {
    $itemName = '2026_05_10_190832_06_create_unwritable_table';
    $sourceDirectory = '/fake/path';
    $sourceFile = $sourceDirectory . DIRECTORY_SEPARATOR . $itemName . '.php';
    $migrationsDirectory = database_path('migrations');

    $filesystem = new FakeMigrationFilesystem([
        'fileExists' => [
            $sourceFile => true,
        ],
        'isDir' => [
            $sourceDirectory . DIRECTORY_SEPARATOR => true,
            $migrationsDirectory => true,
        ],
        'isWritable' => [
            $migrationsDirectory => false,
        ],
    ]);

    app()->instance(MigrationFilesystemInterface::class, $filesystem);
    app()->instance(DatasetPublisher::class, new StubDatasetPublisher);

    artisanCommand('capell:publish-migrations', [
        '--items' => [$itemName],
        '--path' => $sourceDirectory,
    ])->assertFailed()
        ->expectsOutputToContain(sprintf("Cannot publish migrations because '%s' is not writable", $migrationsDirectory));

    expect(collect($filesystem->calls)->contains(
        fn (array $call): bool => $call[0] === 'copy',
    ))->toBeFalse();
});

it('warns and skips if source file does not exist', function (): void {
    $itemName = '2026_05_10_190832_05_missing_source';
    $sourceDirectory = '/fake/path';
    $sourceFile = $sourceDirectory . DIRECTORY_SEPARATOR . $itemName . '.php';
    $stubFile = $sourceFile . '.stub';

    $filesystem = new FakeMigrationFilesystem([
        'fileExists' => [
            $sourceFile => false,
            $stubFile => false,
        ],
        'isDir' => [
            $sourceDirectory . DIRECTORY_SEPARATOR => true,
        ],
    ]);

    app()->instance(MigrationFilesystemInterface::class, $filesystem);
    app()->instance(DatasetPublisher::class, new StubDatasetPublisher);

    artisanCommand('capell:publish-migrations', [
        '--items' => [$itemName],
        '--path' => $sourceDirectory,
    ])->assertExitCode(0)
        ->expectsOutputToContain('does not exist. Skipping.')
        ->expectsOutputToContain('Publish report: 0 applied, 1 blocked.');
});

it('fails if items are missing', function (): void {
    app()->instance(MigrationFilesystemInterface::class, new FakeMigrationFilesystem);
    app()->instance(DatasetPublisher::class, new StubDatasetPublisher);

    artisanCommand('capell:publish-migrations', [
        '--path' => '/fake/path',
    ])->assertExitCode(1)
        ->expectsOutputToContain('The --items option is required.');
});

it('fails if path is not a directory', function (): void {
    $sourceDirectory = '/not/a/dir';

    app()->instance(MigrationFilesystemInterface::class, new FakeMigrationFilesystem([
        'isDir' => [
            $sourceDirectory . DIRECTORY_SEPARATOR => false,
        ],
    ]));
    app()->instance(DatasetPublisher::class, new StubDatasetPublisher);

    artisanCommand('capell:publish-migrations', [
        '--items' => ['2026_05_10_190832_01_create_test_table'],
        '--path' => $sourceDirectory,
    ])->assertExitCode(1)
        ->expectsOutputToContain('The --path option must be a valid directory if provided.');
});

it('fails if type is invalid', function (): void {
    app()->instance(MigrationFilesystemInterface::class, new FakeMigrationFilesystem);
    app()->instance(DatasetPublisher::class, new StubDatasetPublisher);

    artisanCommand('capell:publish-migrations', [
        '--items' => ['2026_05_10_190832_01_create_test_table'],
        '--path' => '/fake/path',
        '--type' => 'invalid',
    ])->assertExitCode(1)
        ->expectsOutputToContain('The --type option must be either');
});

it('publishes direct stub paths and stops immediately on direct missing files', function (): void {
    $migrationsDirectory = database_path('migrations');
    $stubPath = '/fake/vendor/database/migrations/2026_05_10_190832_08_create_direct_stub.php.stub';
    $basePath = '/fake/vendor/database/migrations/2026_05_10_190832_09_create_extensionless';
    $phpPath = $basePath . '.php';

    $filesystem = new FakeMigrationFilesystem([
        'fileExists' => [
            $stubPath => true,
            $basePath => true,
            $phpPath => true,
            '/fake/missing.php' => false,
        ],
        'isDir' => [
            $migrationsDirectory => true,
        ],
    ]);

    app()->instance(MigrationFilesystemInterface::class, $filesystem);
    app()->instance(DatasetPublisher::class, new StubDatasetPublisher);

    $result = PublishMigrationsAction::run('migrations', [$stubPath, $basePath]);

    expect($result->successful())->toBeTrue()
        ->and($result->applied)->toBe(2)
        ->and(collect($filesystem->calls)->contains(
            fn (array $call): bool => $call === [
                'copy',
                $stubPath,
                $migrationsDirectory . DIRECTORY_SEPARATOR . '2026_05_10_190832_08_create_direct_stub.php',
            ],
        ))->toBeTrue()
        ->and(collect($filesystem->calls)->contains(
            fn (array $call): bool => $call === [
                'copy',
                $phpPath,
                $migrationsDirectory . DIRECTORY_SEPARATOR . '2026_05_10_190832_09_create_extensionless.php',
            ],
        ))->toBeTrue();

    $missing = PublishMigrationsAction::run('migrations', ['/fake/missing.php']);

    expect($missing->successful())->toBeFalse()
        ->and($missing->errors)->toBe(["File '/fake/missing.php' does not exist."]);
});
