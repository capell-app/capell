<?php

declare(strict_types=1);

use Capell\Core\Actions\Backup\CreateBackupAction;
use Capell\Core\Actions\Backup\InspectBackupHealthAction;
use Capell\Core\Actions\Backup\PruneBackupsAction;
use Capell\Core\Support\Backup\BackupArtifactStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('backups');
    $this->databasePath = sys_get_temp_dir() . '/capell-health-backup-' . bin2hex(random_bytes(6)) . '.sqlite';
    (new PDO('sqlite:' . $this->databasePath))->exec('CREATE TABLE examples (value TEXT NOT NULL)');
    config([
        'backup.enabled' => true,
        'backup.disk' => 'backups',
        'backup.prefix' => 'capell-backups',
        'backup.connection' => 'backup_test',
        'backup.media_disks' => [],
        'backup.max_age_hours' => 2,
        'backup.minimum_retained' => 1,
        'backup.retain' => 2,
        'database.connections.backup_test' => ['driver' => 'sqlite', 'database' => $this->databasePath],
    ]);
    Carbon::setTestNow('2026-07-10 12:00:00 UTC');
});

afterEach(function (): void {
    Carbon::setTestNow();

    if (is_file($this->databasePath)) {
        unlink($this->databasePath);
    }
});

it('reports healthy only when freshness retention and artifact integrity pass', function (): void {
    resolve(CreateBackupAction::class)->handle(databaseOnly: true);

    $report = resolve(InspectBackupHealthAction::class)->handle();

    expect($report->passed())->toBeTrue()
        ->and($report->snapshotCount)->toBe(1)
        ->and($report->checks)->each->toMatchArray(['passed' => true]);
});

it('reports stale missing and checksum-mismatched backup artifacts', function (): void {
    $manifest = resolve(CreateBackupAction::class)->handle(databaseOnly: true);
    Carbon::setTestNow('2026-07-10 15:00:01 UTC');

    $stale = resolve(InspectBackupHealthAction::class)->handle();
    $artifact = Storage::disk('backups')->get($manifest->database->path);

    if (! is_string($artifact) || $artifact === '') {
        throw new RuntimeException('Backup integrity fixture is missing.');
    }

    $artifact[0] = $artifact[0] === 'x' ? 'y' : 'x';
    Storage::disk('backups')->put($manifest->database->path, $artifact);
    $corrupt = resolve(InspectBackupHealthAction::class)->handle();
    Storage::disk('backups')->delete($manifest->database->path);
    $missing = resolve(InspectBackupHealthAction::class)->handle();

    expect($stale->passed())->toBeFalse()
        ->and(backupHealthCheck($stale->checks, 'freshness')['passed'])->toBeFalse()
        ->and(backupHealthCheck($corrupt->checks, 'integrity')['message'])->toContain('checksum')
        ->and(backupHealthCheck($missing->checks, 'integrity')['message'])->toContain('missing');
});

it('fails health for disabled storage no manifests and insufficient retention', function (): void {
    $empty = resolve(InspectBackupHealthAction::class)->handle();
    config(['backup.enabled' => false]);
    $disabled = resolve(InspectBackupHealthAction::class)->handle();

    expect($empty->passed())->toBeFalse()
        ->and(backupHealthCheck($empty->checks, 'snapshots')['passed'])->toBeFalse()
        ->and(backupHealthCheck($empty->checks, 'retention')['passed'])->toBeFalse()
        ->and($disabled->passed())->toBeFalse()
        ->and(backupHealthCheck($disabled->checks, 'configuration')['message'])->toBe('Backups are disabled.');
});

it('rejects future-dated manifests as invalid rather than permanently fresh', function (): void {
    $manifest = resolve(CreateBackupAction::class)->handle(databaseOnly: true);
    $manifestPath = 'capell-backups/' . $manifest->snapshotId . '/manifest.json';
    $stored = Storage::disk('backups')->json($manifestPath);
    $stored['created_at'] = '2027-07-10T12:00:00+00:00';
    Storage::disk('backups')->put($manifestPath, json_encode($stored, JSON_THROW_ON_ERROR));

    $report = resolve(InspectBackupHealthAction::class)->handle();

    expect($report->passed())->toBeFalse()
        ->and(backupHealthCheck($report->checks, 'freshness')['passed'])->toBeFalse()
        ->and(backupHealthCheck($report->checks, 'integrity')['message'])->toContain('invalid manifest');
});

it('previews pruning and deletes only completed snapshots beyond retention when forced', function (): void {
    $snapshots = [];

    foreach (['12:00:00', '12:01:00', '12:02:00'] as $time) {
        Carbon::setTestNow('2026-07-10 ' . $time . ' UTC');
        $snapshots[] = resolve(CreateBackupAction::class)->handle(databaseOnly: true)->snapshotId;
    }

    $prune = resolve(PruneBackupsAction::class);

    expect($prune->handle())->toBe([$snapshots[0]])
        ->and(Storage::disk('backups')->exists('capell-backups/' . $snapshots[0] . '/manifest.json'))->toBeTrue()
        ->and($prune->handle(force: true))->toBe([$snapshots[0]])
        ->and(Storage::disk('backups')->exists('capell-backups/' . $snapshots[0] . '/manifest.json'))->toBeFalse()
        ->and(Storage::disk('backups')->exists('capell-backups/' . $snapshots[1] . '/manifest.json'))->toBeTrue();
});

it('rejects traversal and out-of-prefix snapshot deletion', function (): void {
    expect(fn (): string => resolve(BackupArtifactStore::class)->snapshotPath('../production', 'manifest.json'))
        ->toThrow(RuntimeException::class, 'snapshot identifier is invalid');
});

it('exposes health JSON and dry-run pruning through commands', function (): void {
    resolve(CreateBackupAction::class)->handle(databaseOnly: true);

    artisanCommand('capell:backup:health', ['--json' => true])
        ->expectsOutputToContain('"status": "healthy"')
        ->assertSuccessful();
    artisanCommand('capell:backup:prune')
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();
});

/**
 * @param  list<array{name: string, passed: bool, message: string}>  $checks
 * @return array{name: string, passed: bool, message: string}
 */
function backupHealthCheck(array $checks, string $name): array
{
    foreach ($checks as $check) {
        if ($check['name'] === $name) {
            return $check;
        }
    }

    throw new RuntimeException(sprintf('Backup health check [%s] was not reported.', $name));
}
