<?php

declare(strict_types=1);

use Capell\Core\Actions\Backup\CreateBackupAction;
use Capell\Core\Data\Backup\BackupManifestData;
use Capell\Core\Support\Backup\BackupTemporaryFiles;
use Capell\Core\Tests\Support\Stubs\RecordingBackupFilesystem;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('backups');
    Storage::fake('media');
    $this->temporaryFilesDirectory = sys_get_temp_dir() . '/capell-create-scratch-' . bin2hex(random_bytes(8));
    app()->instance(BackupTemporaryFiles::class, new BackupTemporaryFiles($this->temporaryFilesDirectory));

    $this->databasePath = sys_get_temp_dir() . '/capell-create-backup-' . bin2hex(random_bytes(6)) . '.sqlite';
    $database = new PDO('sqlite:' . $this->databasePath);
    $database->exec('CREATE TABLE examples (value TEXT NOT NULL)');
    $database->exec("INSERT INTO examples (value) VALUES ('backed-up')");

    config([
        'backup.enabled' => true,
        'backup.disk' => 'backups',
        'backup.prefix' => 'capell-backups',
        'backup.connection' => 'backup_test',
        'backup.media_disks' => ['media'],
        'backup.scratch.sqlite_directory' => sys_get_temp_dir() . '/capell-backup-scratch',
        'database.connections.backup_test' => [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
        ],
    ]);
});

afterEach(function (): void {
    new Filesystem()->deleteDirectory($this->temporaryFilesDirectory);
    if (is_file($this->databasePath)) {
        unlink($this->databasePath);
    }
});

it('creates a database and media snapshot and writes its manifest last', function (): void {
    Storage::disk('media')->put('images/example.txt', 'media-content');

    $manifest = CreateBackupAction::run();
    $manifestPath = 'capell-backups/' . $manifest->snapshotId . '/manifest.json';

    Storage::disk('backups')->assertExists($manifestPath);
    Storage::disk('backups')->assertExists($manifest->database->path);
    Storage::disk('backups')->assertExists($manifest->media[0]->path);

    $databaseContents = gzdecode(Storage::disk('backups')->get($manifest->database->path));
    $temporaryDatabase = sys_get_temp_dir() . '/capell-created-backup-read-' . bin2hex(random_bytes(6)) . '.sqlite';
    file_put_contents($temporaryDatabase, $databaseContents);

    expect(backupCreatedValue($temporaryDatabase))->toBe('backed-up')
        ->and($manifest->database->sha256)->toBe(hash('sha256', (string) Storage::disk('backups')->get($manifest->database->path)))
        ->and($manifest->media)->toHaveCount(1)
        ->and($manifest->media[0]->sourceDisk)->toBe('media')
        ->and($manifest->media[0]->sourcePath)->toBe('images/example.txt')
        ->and(Storage::disk('backups')->get($manifest->media[0]->path))->toBe('media-content')
        ->and(Storage::disk('backups')->json($manifestPath))->toBe($manifest->toArray());

    unlink($temporaryDatabase);
});

it('supports database-only snapshots', function (): void {
    Storage::disk('media')->put('images/example.txt', 'media-content');

    $manifest = CreateBackupAction::run(databaseOnly: true);

    expect($manifest->media)->toBe([])
        ->and(Storage::disk('backups')->allFiles('capell-backups/' . $manifest->snapshotId))->toHaveCount(2);
});

it('preserves distinct disk names when constructing backup artifact paths', function (): void {
    Storage::fake('media one');
    Storage::fake('media?one');
    config(['backup.media_disks' => ['media one', 'media?one']]);
    Storage::disk('media one')->put('same.txt', 'first content');
    Storage::disk('media?one')->put('same.txt', 'second content');

    $manifest = CreateBackupAction::run();

    expect($manifest->media[0]->path)->not->toBe($manifest->media[1]->path)
        ->and(Storage::disk('backups')->get($manifest->media[0]->path))->toBe('first content')
        ->and(Storage::disk('backups')->get($manifest->media[1]->path))->toBe('second content');
});

it('fails closed when backup is disabled or recursively targets its backup disk', function (): void {
    config(['backup.enabled' => false]);

    expect(fn () => CreateBackupAction::run())
        ->toThrow(RuntimeException::class, 'Backups are disabled.')
        ->and(function (): void {
            config(['backup.enabled' => true, 'backup.media_disks' => ['backups']]);
            CreateBackupAction::run();
        })->toThrow(RuntimeException::class, 'Backup storage cannot also be a media source.');
});

it('bounds backup media scratch usage by the active artifact and cleans up on failure', function (?string $failure): void {
    $source = new RecordingBackupFilesystem(Storage::disk('media'));
    $destination = new RecordingBackupFilesystem(Storage::disk('backups'));
    Storage::set('media', $source);
    Storage::set('backups', $destination);

    foreach (range(1, 12) as $index) {
        $source->put(sprintf('%02d.txt', $index), str_repeat('x', 128));
    }

    $source->failReadAt = $failure === 'read' ? 3 : null;
    $destination->failWriteAt = $failure === 'write' ? 4 : null;

    if ($failure === null) {
        $manifest = CreateBackupAction::run();
        expect($manifest->media)->toHaveCount(12);
    } else {
        expect(fn (): BackupManifestData => CreateBackupAction::run())->toThrow(RuntimeException::class);
        expect($destination->allFiles())->toBeEmpty();
    }

    expect($destination->peakMediaBytes)->toBe(128)
        ->and(glob($this->temporaryFilesDirectory . '/*'))->toBe([])
        ->and(array_filter($destination->temporaryPaths, is_file(...)))->toBeEmpty();
})->with([null, 'read', 'write']);

function backupCreatedValue(string $databasePath): string
{
    $statement = new PDO('sqlite:' . $databasePath)->query('SELECT value FROM examples');

    throw_if($statement === false, RuntimeException::class, 'Unable to read the created backup fixture.');

    $value = $statement->fetchColumn();

    return is_string($value) ? $value : throw new RuntimeException('Created backup fixture value is missing.');
}
