<?php

declare(strict_types=1);

use Capell\Core\Actions\Backup\CreateBackupAction;
use Capell\Core\Actions\Backup\RestoreBackupAction;
use Capell\Core\Data\Backup\BackupRestoreResultData;
use Capell\Core\Support\Backup\BackupArtifactStore;
use Capell\Core\Support\Backup\BackupTemporaryFiles;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Capell\Core\Tests\Support\Stubs\RecordingBackupFilesystem;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

use function Orchestra\Testbench\package_path;

use Symfony\Component\Process\Process;

beforeEach(function (): void {
    Storage::fake('backups');
    Storage::fake('media');
    Storage::fake('scratch-media');
    $this->temporaryFilesDirectory = sys_get_temp_dir() . '/capell-restore-temp-' . bin2hex(random_bytes(8));
    app()->instance(BackupTemporaryFiles::class, new BackupTemporaryFiles($this->temporaryFilesDirectory));
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
    new Filesystem()->deleteDirectory($this->temporaryFilesDirectory);
    if (is_file($this->databasePath)) {
        unlink($this->databasePath);
    }

    foreach (glob($this->scratchDirectory . '/*') ?: [] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }

    if (is_dir($this->scratchDirectory)) {
        rmdir($this->scratchDirectory);
    }
});

it('restores a verified snapshot only into scratch database and media targets', function (): void {
    Storage::disk('media')->put('images/example.txt', 'original-media');
    $manifest = CreateBackupAction::run();
    new PDO('sqlite:' . $this->databasePath)->exec("UPDATE examples SET value = 'changed-live'");

    $result = RestoreBackupAction::run(
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
        ->and($this->doctorProcesses->environments[0])->toBe([
            'APP_CONFIG_CACHE' => false,
            'APP_PACKAGES_CACHE' => false,
            'APP_SERVICES_CACHE' => false,
            'APP_ROUTES_CACHE' => false,
            'APP_EVENTS_CACHE' => false,
            'TESTBENCH_WORKING_PATH' => package_path(),
        ]);
});

it('rejects unsafe or live restore targets and non-empty media prefixes', function (): void {
    Storage::disk('media')->put('images/example.txt', 'original-media');
    $manifest = CreateBackupAction::run();

    expect(fn () => RestoreBackupAction::run($manifest->snapshotId, '../live', 'scratch-media', 'restore-test'))
        ->toThrow(InvalidArgumentException::class, 'safe scratch database')
        ->and(fn () => RestoreBackupAction::run($manifest->snapshotId, 'capell_restore_test', 'media', 'restore-test'))
        ->toThrow(InvalidArgumentException::class, 'different from every live media disk');

    Storage::disk('scratch-media')->put('restore-test/existing.txt', 'occupied');

    expect(fn () => RestoreBackupAction::run($manifest->snapshotId, 'capell_restore_test', 'scratch-media', 'restore-test'))
        ->toThrow(InvalidArgumentException::class, 'must be empty');
});

it('rejects a currently configured live media disk absent from an older snapshot', function (): void {
    Storage::fake('current-media');
    Storage::disk('media')->put('archived.txt', 'snapshot-media');
    $manifest = CreateBackupAction::run();
    Storage::disk('current-media')->put('live.txt', 'keep');
    config(['backup.media_disks' => ['current-media']]);

    expect(fn (): BackupRestoreResultData => RestoreBackupAction::run($manifest->snapshotId, 'capell_restore_test', 'current-media', 'restored'))
        ->toThrow(InvalidArgumentException::class, 'different from every live media disk')
        ->and(is_dir($this->scratchDirectory))->toBeFalse()
        ->and(Storage::disk('current-media')->allFiles())->toBe(['live.txt'])
        ->and(Storage::disk('current-media')->get('live.txt'))->toBe('keep');
});

it('rejects checksum failures before creating a scratch database', function (): void {
    $manifest = CreateBackupAction::run(databaseOnly: true);
    Storage::disk('backups')->put($manifest->database->path, 'corrupt');

    expect(fn () => RestoreBackupAction::run($manifest->snapshotId, 'capell_restore_test'))
        ->toThrow(RuntimeException::class, 'failed integrity verification')
        ->and(is_dir($this->scratchDirectory))->toBeFalse();
});

it('preserves the source disk for same-path files in multi-disk snapshots', function (): void {
    Storage::fake('second-media');
    config(['backup.media_disks' => ['media', 'second-media']]);
    Storage::disk('media')->put('same.txt', 'first-file');
    Storage::disk('second-media')->put('same.txt', 'second-file');
    $manifest = CreateBackupAction::run();

    $result = RestoreBackupAction::run($manifest->snapshotId, 'capell_restore_test', 'scratch-media', 'restored');

    expect($result->mediaFiles)->toBe(2)
        ->and(Storage::disk('scratch-media')->allFiles('restored'))->toHaveCount(2);

    foreach ($manifest->media as $artifact) {
        $contents = Storage::disk('scratch-media')->get('restored/' . $artifact->sourceDisk . '/' . $artifact->sourcePath);
        expect($contents)->toBeString();
        throw_unless(is_string($contents), RuntimeException::class, 'Restored media is missing.');
        expect(hash('sha256', $contents))->toBe($artifact->sha256);
    }
});

it('rejects colliding or unsafe media mappings before creating scratch data', function (string $sourcePath): void {
    Storage::disk('media')->put('same.txt', 'original');
    $manifest = CreateBackupAction::run();
    $duplicate = clone $manifest->media[0];
    $duplicate->sourcePath = $sourcePath;
    $manifest->media[] = $duplicate;
    resolve(BackupArtifactStore::class)->putManifest($manifest->snapshotId, $manifest->toArray());

    expect(fn (): BackupRestoreResultData => RestoreBackupAction::run($manifest->snapshotId, 'capell_restore_test', 'scratch-media', 'restored'))
        ->toThrow(RuntimeException::class)
        ->and(is_dir($this->scratchDirectory))->toBeFalse()
        ->and(Storage::disk('scratch-media')->allFiles())->toBeEmpty();
})->with(['same.txt', 'same.txt/child.txt', '../unsafe.txt', 'same.txt\\child.txt']);

it('rejects unicode-equivalent media destinations before creating scratch data', function (string $sourcePath): void {
    Storage::disk('media')->put('café.txt', 'original');
    $manifest = CreateBackupAction::run();
    $duplicate = clone $manifest->media[0];
    $duplicate->sourcePath = $sourcePath;
    $manifest->media[] = $duplicate;
    resolve(BackupArtifactStore::class)->putManifest($manifest->snapshotId, $manifest->toArray());

    expect(fn (): BackupRestoreResultData => RestoreBackupAction::run($manifest->snapshotId, 'capell_restore_test', 'scratch-media', 'restored'))
        ->toThrow(RuntimeException::class, __('capell-core::backup.destinations_collide'))
        ->and(is_dir($this->scratchDirectory))->toBeFalse()
        ->and(Storage::disk('scratch-media')->allFiles())->toBeEmpty();
})->with(['CAFÉ.txt', "cafe\u{0301}.txt", 'CAFÉ.txt/child.txt']);

it('rejects media prefixes beneath an existing file before creating scratch data', function (): void {
    Storage::disk('media')->put('original.txt', 'original');
    Storage::disk('scratch-media')->put('occupied', 'keep');
    $manifest = CreateBackupAction::run();

    expect(fn (): BackupRestoreResultData => RestoreBackupAction::run($manifest->snapshotId, 'capell_restore_test', 'scratch-media', 'occupied/nested/restored'))
        ->toThrow(InvalidArgumentException::class)
        ->and(is_dir($this->scratchDirectory))->toBeFalse()
        ->and(Storage::disk('scratch-media')->allFiles())->toBe(['occupied'])
        ->and(Storage::disk('scratch-media')->get('occupied'))->toBe('keep');
});

it('rejects media prefixes through a symlink outside the scratch disk before creating scratch data', function (): void {
    Storage::disk('media')->put('original.txt', 'original');
    $manifest = CreateBackupAction::run();
    $outsideDirectory = sys_get_temp_dir() . '/capell-restore-outside-' . bin2hex(random_bytes(8));
    mkdir($outsideDirectory, 0755, true);
    file_put_contents($outsideDirectory . '/live.txt', 'keep');
    $link = Storage::disk('scratch-media')->path('linked');
    symlink($outsideDirectory, $link);

    try {
        expect(fn (): BackupRestoreResultData => RestoreBackupAction::run($manifest->snapshotId, 'capell_restore_test', 'scratch-media', 'linked/restored'))
            ->toThrow(InvalidArgumentException::class, __('capell-core::backup.destinations_collide'))
            ->and(is_dir($this->scratchDirectory))->toBeFalse()
            ->and(file_exists($outsideDirectory . '/restored/original.txt'))->toBeFalse()
            ->and(file_get_contents($outsideDirectory . '/live.txt'))->toBe('keep');
    } finally {
        DIRECTORY_SEPARATOR === '\\' ? rmdir($link) : unlink($link);
        new Filesystem()->deleteDirectory($outsideDirectory);
    }
});

it('restores ordinary colons while rejecting drive stream and traversal source paths', function (string $sourcePath, bool $safe): void {
    Storage::disk('media')->put($safe ? $sourcePath : 'original.txt', 'preserved report');
    $manifest = CreateBackupAction::run();
    $manifest->media[0]->sourcePath = $sourcePath;
    resolve(BackupArtifactStore::class)->putManifest($manifest->snapshotId, $manifest->toArray());

    if ($safe) {
        $result = RestoreBackupAction::run($manifest->snapshotId, 'capell_restore_test', 'scratch-media', 'restored');

        expect($result->mediaFiles)->toBe(1)
            ->and(Storage::disk('scratch-media')->get('restored/' . $sourcePath))->toBe('preserved report');

        return;
    }

    expect(fn (): BackupRestoreResultData => RestoreBackupAction::run($manifest->snapshotId, 'capell_restore_test', 'scratch-media', 'restored'))
        ->toThrow(RuntimeException::class)
        ->and(is_dir($this->scratchDirectory))->toBeFalse()
        ->and(Storage::disk('scratch-media')->allFiles())->toBeEmpty();
})->with([
    ['report 10:30.pdf', true],
    ['2026-09-23T10:30:00Z/report.pdf', true],
    ['C:/x', false],
    ['C:\\x', false],
    ['C:x', false],
    ['../x', false],
    ['/absolute.txt', false],
    ['report.pdf::$DATA', false],
    ['report.pdf:stream:$DATA', false],
]);

it('bounds restore media scratch usage and cleans completed transfers on failures', function (?string $failure): void {
    foreach (range(1, 12) as $index) {
        Storage::disk('media')->put(sprintf('%02d.txt', $index), str_repeat('x', 128));
    }

    $manifest = CreateBackupAction::run();
    $source = new RecordingBackupFilesystem(Storage::disk('backups'));
    $destination = new RecordingBackupFilesystem(Storage::disk('scratch-media'));
    Storage::set('backups', $source);
    Storage::set('scratch-media', $destination);
    // Integrity preflight reads database + 12 media, then the database download.
    $source->failReadAt = $failure === 'read' ? 17 : null;
    $source->corruptReadAt = $failure === 'checksum' ? 17 : null;

    $destination->failWriteAt = $failure === 'write' ? 3 : null;

    if ($failure === null) {
        $result = RestoreBackupAction::run($manifest->snapshotId, 'capell_restore_test', 'scratch-media', 'restored');
        expect($result->mediaFiles)->toBe(12);
    } else {
        expect(fn (): BackupRestoreResultData => RestoreBackupAction::run($manifest->snapshotId, 'capell_restore_test', 'scratch-media', 'restored'))
            ->toThrow(RuntimeException::class);
    }

    expect($destination->peakMediaBytes)->toBe(128)
        ->and(glob($this->temporaryFilesDirectory . '/*'))->toBe([])
        ->and(array_filter($destination->temporaryPaths, is_file(...)))->toBeEmpty();
})->with([null, 'read', 'write', 'checksum']);

function backupRestoredValue(string $databasePath): string
{
    $statement = new PDO('sqlite:' . $databasePath)->query('SELECT value FROM examples');

    throw_if($statement === false, RuntimeException::class, 'Unable to read the restored backup fixture.');

    $value = $statement->fetchColumn();

    return is_string($value) ? $value : throw new RuntimeException('Restored backup fixture value is missing.');
}

final class RecordingDoctorProcessFactory implements ProcessFactoryInterface
{
    /** @var list<list<string>|string> */
    public array $commands = [];

    /** @var list<array<string, string|false>> */
    public array $environments = [];

    public function make(array|string $command, ?string $cwd = null, ?array $environment = null): Process
    {
        $this->commands[] = $command;
        $this->environments[] = $environment ?? [];

        return new Process(['/usr/bin/printf', '{"status":"passed","checks":[]}']);
    }
}

it('rejects NTFS stream and trailing-dot names only when restoring on Windows', function (string $path, bool $safeOnPosix, bool $safeOnWindows): void {
    expect(RestoreBackupAction::isSafeRelativePath($path, 'Linux'))->toBe($safeOnPosix)
        ->and(RestoreBackupAction::isSafeRelativePath($path, 'Darwin'))->toBe($safeOnPosix)
        ->and(RestoreBackupAction::isSafeRelativePath($path, 'Windows'))->toBe($safeOnWindows);
})->with([
    'untyped stream' => ['images/logo.png:payload', true, false],
    'typed stream' => ['images/logo.png::$DATA', false, false],
    'colon in name' => ['report 10:30.pdf', true, false],
    'trailing dot' => ['images/logo.', true, false],
    'trailing space' => ['images/logo ', true, false],
    'drive path' => ['C:/Windows/x', false, false],
    'traversal' => ['../x', false, false],
    'plain file' => ['images/logo.png', true, true],
]);
