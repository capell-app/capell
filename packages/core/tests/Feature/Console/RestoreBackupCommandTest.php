<?php

declare(strict_types=1);

use Capell\Core\Actions\Backup\CreateBackupAction;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Capell\Core\Tests\Support\BackupSigningTestKeys;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    BackupSigningTestKeys::configure();
    Storage::fake('backups');
    $this->databasePath = sys_get_temp_dir() . '/capell-restore-command-' . bin2hex(random_bytes(6)) . '.sqlite';
    $this->scratchDirectory = sys_get_temp_dir() . '/capell-restore-command-scratch-' . bin2hex(random_bytes(6));
    new PDO('sqlite:' . $this->databasePath)->exec('CREATE TABLE examples (value TEXT NOT NULL)');
    config([
        'capell-backup.enabled' => true,
        'capell-backup.disk' => 'backups',
        'capell-backup.prefix' => 'capell-backups',
        'capell-backup.connection' => 'backup_test',
        'capell-backup.media_disks' => [],
        'capell-backup.scratch.database_prefix' => 'capell_restore_',
        'capell-backup.scratch.sqlite_directory' => $this->scratchDirectory,
        'database.connections.backup_test' => ['driver' => 'sqlite', 'database' => $this->databasePath],
    ]);
    app()->instance(ProcessFactoryInterface::class, new class implements ProcessFactoryInterface
    {
        #[Override]
        public function make(array|string $command, ?string $cwd = null, ?array $environment = null): Process
        {
            return new Process(['/usr/bin/printf', '{"status":"passed","checks":[]}']);
        }
    });
});

afterEach(function (): void {
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

it('restores and verifies a snapshot from the command', function (): void {
    $snapshotId = CreateBackupAction::run(databaseOnly: true)->snapshotId;

    artisanCommand('capell:backup:restore', [
        'snapshot' => $snapshotId,
        'scratch-database' => 'capell_restore_command',
    ])->expectsOutputToContain('Scratch restore verified:')
        ->assertSuccessful();
});
