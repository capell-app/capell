<?php

declare(strict_types=1);

use Capell\Core\Actions\Backup\CreateBackupAction;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('backups');
    Storage::fake('media');

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
    if (is_file($this->databasePath)) {
        unlink($this->databasePath);
    }
});

it('creates a database and media snapshot and writes its manifest last', function (): void {
    Storage::disk('media')->put('images/example.txt', 'media-content');

    $manifest = resolve(CreateBackupAction::class)->handle();
    $manifestPath = 'capell-backups/' . $manifest->snapshotId . '/manifest.json';

    Storage::disk('backups')->assertExists($manifestPath);
    Storage::disk('backups')->assertExists($manifest->database->path);
    Storage::disk('backups')->assertExists($manifest->media[0]->path);

    $databaseContents = gzdecode(Storage::disk('backups')->get($manifest->database->path));
    $temporaryDatabase = sys_get_temp_dir() . '/capell-created-backup-read-' . bin2hex(random_bytes(6)) . '.sqlite';
    file_put_contents($temporaryDatabase, $databaseContents);

    expect(backupCreatedValue($temporaryDatabase))->toBe('backed-up')
        ->and($manifest->database->sha256)->toBe(hash('sha256', Storage::disk('backups')->get($manifest->database->path)))
        ->and($manifest->media)->toHaveCount(1)
        ->and($manifest->media[0]->sourceDisk)->toBe('media')
        ->and($manifest->media[0]->sourcePath)->toBe('images/example.txt')
        ->and(Storage::disk('backups')->get($manifest->media[0]->path))->toBe('media-content')
        ->and(Storage::disk('backups')->json($manifestPath))->toBe($manifest->toArray());

    unlink($temporaryDatabase);
});

it('supports database-only snapshots', function (): void {
    Storage::disk('media')->put('images/example.txt', 'media-content');

    $manifest = resolve(CreateBackupAction::class)->handle(databaseOnly: true);

    expect($manifest->media)->toBe([])
        ->and(Storage::disk('backups')->allFiles('capell-backups/' . $manifest->snapshotId))->toHaveCount(2);
});

it('fails closed when backup is disabled or recursively targets its backup disk', function (): void {
    config(['backup.enabled' => false]);

    expect(fn () => resolve(CreateBackupAction::class)->handle())
        ->toThrow(RuntimeException::class, 'Backups are disabled.')
        ->and(function (): void {
            config(['backup.enabled' => true, 'backup.media_disks' => ['backups']]);
            resolve(CreateBackupAction::class)->handle();
        })->toThrow(RuntimeException::class, 'Backup storage cannot also be a media source.');
});

function backupCreatedValue(string $databasePath): string
{
    $statement = (new PDO('sqlite:' . $databasePath))->query('SELECT value FROM examples');

    if ($statement === false) {
        throw new RuntimeException('Unable to read the created backup fixture.');
    }

    $value = $statement->fetchColumn();

    return is_string($value) ? $value : throw new RuntimeException('Created backup fixture value is missing.');
}
