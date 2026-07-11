<?php

declare(strict_types=1);

use Capell\Core\Actions\Backup\CreateBackupAction;
use Capell\Core\Actions\Backup\RestoreBackupAction;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    Storage::fake('backups');
    Storage::fake('media');
    Storage::fake('scratch-media');
    $this->databasePath = sys_get_temp_dir() . '/capell-restore-live-' . bin2hex(random_bytes(6)) . '.sqlite';
    $this->scratchDirectory = sys_get_temp_dir() . '/capell-restore-scratch-' . bin2hex(random_bytes(6));
    $database = new PDO('sqlite:' . $this->databasePath);
    $database->exec('CREATE TABLE examples (value TEXT NOT NULL)');
    $database->exec("INSERT INTO examples (value) VALUES ('original')");
    config([
        'backup.enabled' => true,
        'backup.disk' => 'backups',
        'backup.prefix' => 'capell-backups',
        'backup.connection' => 'backup_test',
        'backup.media_disks' => ['media'],
        'backup.scratch.database_prefix' => 'capell_restore_',
        'backup.scratch.sqlite_directory' => $this->scratchDirectory,
        'database.connections.backup_test' => ['driver' => 'sqlite', 'database' => $this->databasePath],
    ]);
    $this->doctorProcesses = new RecordingDoctorProcessFactory;
    app()->instance(ProcessFactoryInterface::class, $this->doctorProcesses);
});

afterEach(function (): void {
    if (is_file($this->databasePath)) {
        unlink($this->databasePath);
    }

    foreach (glob($this->scratchDirectory . '/*') ?: [] as $path) {
        is_file($path) && unlink($path);
    }

    is_dir($this->scratchDirectory) && rmdir($this->scratchDirectory);
});

it('restores a verified snapshot only into scratch database and media targets', function (): void {
    Storage::disk('media')->put('images/example.txt', 'original-media');
    $manifest = resolve(CreateBackupAction::class)->handle();
    (new PDO('sqlite:' . $this->databasePath))->exec("UPDATE examples SET value = 'changed-live'");

    $result = resolve(RestoreBackupAction::class)->handle(
        snapshotId: $manifest->snapshotId,
        scratchDatabase: 'capell_restore_test',
        mediaDisk: 'scratch-media',
        mediaPrefix: 'restore-test',
    );

    expect(backupRestoredValue($result->database))->toBe('original')
        ->and(backupRestoredValue($this->databasePath))->toBe('changed-live')
        ->and(Storage::disk('scratch-media')->get('restore-test/images/example.txt'))->toBe('original-media')
        ->and($result->mediaFiles)->toBe(1)
        ->and($result->doctorStatus)->toBe('passed')
        ->and($this->doctorProcesses->commands[0])->toContain(
            '--connection=backup_test',
            '--database=' . $result->database,
        )
        ->and($this->doctorProcesses->environments[0])->toBe([]);
});

it('rejects unsafe or live restore targets and non-empty media prefixes', function (): void {
    Storage::disk('media')->put('images/example.txt', 'original-media');
    $manifest = resolve(CreateBackupAction::class)->handle();

    expect(fn () => resolve(RestoreBackupAction::class)->handle($manifest->snapshotId, '../live', 'scratch-media', 'restore-test'))
        ->toThrow(InvalidArgumentException::class, 'safe scratch database')
        ->and(fn () => resolve(RestoreBackupAction::class)->handle($manifest->snapshotId, 'capell_restore_test', 'media', 'restore-test'))
        ->toThrow(InvalidArgumentException::class, 'different from every live media disk');

    Storage::disk('scratch-media')->put('restore-test/existing.txt', 'occupied');

    expect(fn () => resolve(RestoreBackupAction::class)->handle($manifest->snapshotId, 'capell_restore_test', 'scratch-media', 'restore-test'))
        ->toThrow(InvalidArgumentException::class, 'must be empty');
});

it('rejects checksum failures before creating a scratch database', function (): void {
    $manifest = resolve(CreateBackupAction::class)->handle(databaseOnly: true);
    Storage::disk('backups')->put($manifest->database->path, 'corrupt');

    expect(fn () => resolve(RestoreBackupAction::class)->handle($manifest->snapshotId, 'capell_restore_test'))
        ->toThrow(RuntimeException::class, 'failed integrity verification')
        ->and(is_dir($this->scratchDirectory))->toBeFalse();
});

function backupRestoredValue(string $databasePath): string
{
    $statement = (new PDO('sqlite:' . $databasePath))->query('SELECT value FROM examples');

    if ($statement === false) {
        throw new RuntimeException('Unable to read the restored backup fixture.');
    }

    $value = $statement->fetchColumn();

    return is_string($value) ? $value : throw new RuntimeException('Restored backup fixture value is missing.');
}

final class RecordingDoctorProcessFactory implements ProcessFactoryInterface
{
    /** @var list<list<string>|string> */
    public array $commands = [];

    /** @var list<array<string, string>> */
    public array $environments = [];

    public function make(array|string $command, ?string $cwd = null, ?array $environment = null): Process
    {
        $this->commands[] = $command;
        $this->environments[] = $environment ?? [];

        return new Process(['/usr/bin/printf', '{"status":"passed","checks":[]}']);
    }
}
