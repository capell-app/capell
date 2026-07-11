<?php

declare(strict_types=1);

use Capell\Core\Actions\PublishMigrationsAction;
use Capell\Core\Support\Dataset\DatasetPublisher;
use Capell\Core\Support\Migration\MigrationFilesystemInterface;
use Capell\Core\Tests\Support\Stubs\FakeMigrationFilesystem;
use Capell\Core\Tests\Support\Stubs\StubDatasetPublisher;

function bindPublishMigrationsDeps(FakeMigrationFilesystem $filesystem): void
{
    app()->instance(MigrationFilesystemInterface::class, $filesystem);
    app()->instance(DatasetPublisher::class, new StubDatasetPublisher);
}

it('returns a required-items error and copies nothing when no items are given', function (): void {
    $filesystem = new FakeMigrationFilesystem;
    bindPublishMigrationsDeps($filesystem);

    $result = PublishMigrationsAction::run('migrations', []);

    expect($result->successful())->toBeFalse()
        ->and($result->applied)->toBe(0)
        ->and($result->errors)->toBe(['The --items option is required.'])
        ->and(collect($filesystem->calls)->contains(fn (array $call): bool => $call[0] === 'copy'))->toBeFalse();
});

it('rejects an invalid publish type before touching the filesystem', function (): void {
    $filesystem = new FakeMigrationFilesystem;
    bindPublishMigrationsDeps($filesystem);

    $result = PublishMigrationsAction::run('invalid', ['2026_05_10_000000_create_table']);

    expect($result->successful())->toBeFalse()
        ->and($result->errors)->toBe(['The --type option must be either "migrations" or "settings".'])
        ->and(collect($filesystem->calls)->contains(fn (array $call): bool => $call[0] === 'copy'))->toBeFalse();
});

it('refuses to publish when the destination directory is not writable', function (): void {
    $itemName = '2026_05_10_000000_create_unwritable_table';
    $sourceDirectory = '/fake/path';
    $sourceFile = $sourceDirectory . DIRECTORY_SEPARATOR . $itemName . '.php';
    $migrationsDirectory = database_path('migrations');

    $filesystem = new FakeMigrationFilesystem([
        'fileExists' => [$sourceFile => true],
        'isDir' => [
            $sourceDirectory . DIRECTORY_SEPARATOR => true,
            $migrationsDirectory => true,
        ],
        'isWritable' => [$migrationsDirectory => false],
    ]);
    bindPublishMigrationsDeps($filesystem);

    $result = PublishMigrationsAction::run('migrations', [$itemName], $sourceDirectory);

    expect($result->successful())->toBeFalse()
        ->and($result->applied)->toBe(0)
        ->and($result->errors[0] ?? '')->toContain('is not writable')
        ->and(collect($filesystem->calls)->contains(fn (array $call): bool => $call[0] === 'copy'))->toBeFalse();
});

it('publishes a resolved migration file to the database migrations directory', function (): void {
    $itemName = '2026_05_10_000000_create_test_table';
    $sourceDirectory = '/fake/path';
    $sourceFile = $sourceDirectory . DIRECTORY_SEPARATOR . $itemName . '.php';
    $migrationsDirectory = database_path('migrations');

    $filesystem = new FakeMigrationFilesystem([
        'fileExists' => [$sourceFile => true],
        'isDir' => [
            $sourceDirectory . DIRECTORY_SEPARATOR => true,
            $migrationsDirectory => true,
        ],
    ]);
    bindPublishMigrationsDeps($filesystem);

    $result = PublishMigrationsAction::run('migrations', [$itemName], $sourceDirectory);

    expect($result->successful())->toBeTrue()
        ->and($result->applied)->toBe(1)
        ->and($result->blocked)->toBe(0)
        ->and(collect($filesystem->calls)->contains(fn (array $call): bool => $call === [
            'copy',
            $sourceFile,
            $migrationsDirectory . DIRECTORY_SEPARATOR . $itemName . '.php',
        ]))->toBeTrue();
});
