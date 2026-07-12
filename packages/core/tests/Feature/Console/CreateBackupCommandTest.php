<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('backups');
    $this->databasePath = sys_get_temp_dir() . '/capell-create-backup-command-' . bin2hex(random_bytes(6)) . '.sqlite';
    new PDO('sqlite:' . $this->databasePath)->exec('CREATE TABLE examples (value TEXT NOT NULL)');
    config([
        'backup.enabled' => true,
        'backup.disk' => 'backups',
        'backup.prefix' => 'capell-backups',
        'backup.connection' => 'backup_test',
        'backup.media_disks' => [],
        'database.connections.backup_test' => ['driver' => 'sqlite', 'database' => $this->databasePath],
    ]);
});

afterEach(function (): void {
    if (is_file($this->databasePath)) {
        unlink($this->databasePath);
    }
});

it('creates a database-only snapshot from the command', function (): void {
    artisanCommand('capell:backup:create', ['--database-only' => true])
        ->expectsOutputToContain('Backup snapshot created:')
        ->assertSuccessful();

    expect(Storage::disk('backups')->allFiles('capell-backups'))->toHaveCount(2);
});

it('returns failure without sensitive output when backup is unavailable', function (): void {
    config(['backup.enabled' => false]);

    artisanCommand('capell:backup:create')
        ->expectsOutputToContain('Backups are disabled.')
        ->assertFailed();
});
