<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Feature\Console;

use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\UpgradeLogEntry;
use Capell\Core\Models\UpgradeRun;
use Capell\Core\Models\UpgradeRunEvent;
use Capell\Core\Support\Upgrade\DatabaseUpgradeLock;
use Capell\Core\Tests\Feature\Console\Fixtures\CmdTrackedStep;
use Capell\Core\Tests\Feature\Console\Fixtures\MissingDependencyStep;
use Capell\Core\Tests\Feature\Console\Fixtures\ReportOnlyMutatingStep;
use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    CmdTrackedStep::$runs = 0;
    ReportOnlyMutatingStep::$shouldRunCalls = 0;
    app()->tag([CmdTrackedStep::class], 'capell.upgrade-steps');

    // Register stub commands for ones the upgrade pipeline dispatches but testbench lacks.
    Artisan::command('settings:migrate {--force}', fn (): int => Command::SUCCESS)->describe('stub');
    Artisan::command('optimize:clear', fn (): int => Command::SUCCESS)->describe('stub');
    Artisan::command('capell:html-cache:clear', fn (): int => Command::SUCCESS)->describe('stub');
    Artisan::command('capell:test-failing-upgrade', fn (): int => Command::FAILURE)->describe('stub');
});

it('runs the full pipeline and records state in the log', function (): void {
    artisanCommand('capell:upgrade', ['--force' => true, '--no-clear-cache' => true])->assertSuccessful();

    expect(CmdTrackedStep::$runs)->toBe(1)
        ->and(UpgradeLogEntry::query()->steps()->where('key', 'core.cmd-tracked')->where('status', 'success')->exists())->toBeTrue()
        ->and(UpgradeLogEntry::query()->versionSnapshots()->where('key', 'capell-app/capell')->exists())->toBeTrue();
});

it('skips already-applied steps on re-run', function (): void {
    artisanCommand('capell:upgrade', ['--force' => true, '--no-clear-cache' => true])->assertSuccessful();
    artisanCommand('capell:upgrade', ['--force' => true, '--no-clear-cache' => true])->assertSuccessful();

    expect(CmdTrackedStep::$runs)->toBe(1);
});

it('dry-run does not execute steps or write any upgrade state', function (): void {
    artisanCommand('capell:upgrade', ['--dry-run' => true, '--force' => true, '--no-clear-cache' => true])
        ->expectsOutputToContain('DRY RUN')
        ->expectsOutputToContain('Backup prerequisite: unknown')
        ->assertFailed();

    expect(CmdTrackedStep::$runs)->toBe(0)
        ->and(UpgradeLogEntry::query()->count())->toBe(0)
        ->and(UpgradeRun::query()->count())->toBe(0)
        ->and(UpgradeRunEvent::query()->count())->toBe(0);
});

it('reports a blocking preflight without creating upgrade state', function (): void {
    app()->tag([ReportOnlyMutatingStep::class], 'capell.upgrade-steps');
    $cacheSpy = Cache::spy();
    Process::fake();
    $migrationLockPath = storage_path('framework/cache/capell-database-migrations.lock');
    @unlink($migrationLockPath);

    artisanCommand('capell:upgrade', ['--dry-run' => true])
        ->expectsOutputToContain('Installed Composer versions')
        ->expectsOutputToContain('Pending core schema migrations')
        ->expectsOutputToContain('Pending core settings migrations')
        ->expectsOutputToContain('Installed package migrations')
        ->expectsOutputToContain('Pending upgrade steps')
        ->expectsOutputToContain('Manifest audit')
        ->expectsOutputToContain('Backup prerequisite: unknown')
        ->expectsOutputToContain('Migration irreversibility: unknown')
        ->assertFailed();

    expect(CmdTrackedStep::$runs)->toBe(0)
        ->and(ReportOnlyMutatingStep::$shouldRunCalls)->toBe(0)
        ->and(UpgradeLogEntry::query()->count())->toBe(0)
        ->and(UpgradeRun::query()->count())->toBe(0)
        ->and(UpgradeRunEvent::query()->count())->toBe(0)
        ->and(file_exists($migrationLockPath))->toBeFalse();

    $cacheSpy->shouldNotHaveReceived('lock');
    Process::assertNothingRan();
});

it('reports an unregistered package source migration as pending', function (): void {
    $packagePath = sys_get_temp_dir() . '/capell-report-only-migration-' . uniqid();
    $migrationDirectory = $packagePath . '/database/migrations';
    mkdir($migrationDirectory, 0777, true);
    $migrationPath = $migrationDirectory . '/2099_01_01_000000_report_only_source_fixture.php';
    file_put_contents($migrationPath, "<?php\n\ndeclare(strict_types=1);\n");
    CapellCore::forcePackageInstalled('vendor/report-only-migration');
    CapellCore::registerPackage('vendor/report-only-migration', path: $packagePath);

    try {
        artisanCommand('capell:upgrade', ['--dry-run' => true])
            ->expectsOutputToContain('vendor/report-only-migration — schema: unknown pending status')
            ->assertFailed();
    } finally {
        @unlink($migrationPath);
        @rmdir($migrationDirectory);
        @rmdir($packagePath . '/database');
        @rmdir($packagePath);
    }
});

it('audits manifest declarations without autoloading extension classes', function (): void {
    $originalInstalledVersions = InstalledVersions::getRawData();
    $installedVersions = $originalInstalledVersions;
    $packagePath = sys_get_temp_dir() . '/capell-report-only-manifest-' . uniqid();
    $markerPath = $packagePath . '/autoloaded';
    mkdir($packagePath . '/src', 0777, true);
    file_put_contents($packagePath . '/composer.json', json_encode([
        'name' => 'capell-app/report-only-manifest',
        'autoload' => ['psr-4' => ['Vendor\\ReportOnly\\' => 'src/']],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($packagePath . '/capell.json', json_encode(capellManifestV3Array(
        name: 'capell-app/report-only-manifest',
        namespace: 'Vendor\\ReportOnly',
        providers: ['runtime' => ['Vendor\\ReportOnly\\SideEffectProvider']],
        overrides: ['capellApiVersion' => '^3.0'],
    ), JSON_THROW_ON_ERROR));
    file_put_contents($packagePath . '/src/SideEffectProvider.php', "<?php\nfile_put_contents('{$markerPath}', 'loaded');\n");
    $installedVersions['versions']['capell-app/report-only-manifest'] = [
        'pretty_version' => '1.0.0',
        'version' => '1.0.0.0',
        'reference' => null,
        'type' => 'library',
        'install_path' => $packagePath,
        'aliases' => [],
        'dev_requirement' => false,
    ];
    InstalledVersions::reload($installedVersions);

    try {
        artisanCommand('capell:upgrade', ['--dry-run' => true])
            ->expectsOutputToContain('capell-app/report-only-manifest: capellApiVersion does not include 1.1.0.')
            ->assertFailed();

        expect(file_exists($markerPath))->toBeFalse();
    } finally {
        @unlink($packagePath . '/src/SideEffectProvider.php');
        @unlink($packagePath . '/capell.json');
        @unlink($packagePath . '/composer.json');
        @rmdir($packagePath . '/src');
        @rmdir($packagePath);
        InstalledVersions::reload($originalInstalledVersions);
    }
});

it('reports unknown version compatibility when the upgrade ledger is unavailable', function (): void {
    Schema::dropIfExists('capell_upgrade_log');

    artisanCommand('capell:upgrade', ['--dry-run' => true])
        ->expectsOutputToContain('Version-ledger compatibility is unknown')
        ->assertFailed();
});

it('--only-migrations skips upgrade steps', function (): void {
    artisanCommand('capell:upgrade', ['--only-migrations' => true, '--force' => true, '--no-clear-cache' => true])
        ->assertSuccessful();

    expect(CmdTrackedStep::$runs)->toBe(0)
        ->and(UpgradeLogEntry::query()->versionSnapshots()->count())->toBe(0);
});

it('does not record versions when interactive upgrade steps are declined', function (): void {
    artisanCommand('capell:upgrade', ['--skip-migrations' => true, '--no-clear-cache' => true])
        ->expectsConfirmation('Run the above upgrade steps?', 'no')
        ->assertSuccessful();

    expect(CmdTrackedStep::$runs)->toBe(0)
        ->and(UpgradeLogEntry::query()->versionSnapshots()->count())->toBe(0);
});

it('aborts when another upgrade holds the lock', function (): void {
    // The lock is held in the database, so simulating a concurrent upgrade means
    // taking the real lock rather than a cache lock.
    new DatabaseUpgradeLock()->acquire('capell:upgrade', 60);

    artisanCommand('capell:upgrade', ['--force' => true, '--no-clear-cache' => true])
        ->expectsOutputToContain('Another upgrade is running')
        ->assertFailed();
});

it('aborts on detected downgrade without --force-downgrade', function (): void {
    UpgradeLogEntry::query()->create([
        'type' => 'version_snapshot', 'key' => 'capell-app/capell', 'package' => 'capell-app/capell',
        'status' => 'recorded', 'ran_at' => now()->subDay(),
        'meta' => ['to_version' => '99.0.0'],
    ]);

    artisanCommand('capell:upgrade', ['--force' => true, '--no-clear-cache' => true])
        ->expectsOutputToContain('Downgrade detected')
        ->assertFailed();
});

it('re-runs an already applied step with --force-step', function (): void {
    artisanCommand('capell:upgrade', ['--force' => true, '--no-clear-cache' => true])->assertSuccessful();
    artisanCommand('capell:upgrade', [
        '--force' => true,
        '--no-clear-cache' => true,
        '--force-step' => ['core.cmd-tracked'],
    ])->assertSuccessful();

    expect(CmdTrackedStep::$runs)->toBe(2)
        ->and(UpgradeLogEntry::query()->steps()->where('key', 'core.cmd-tracked')->where('status', 'success')->count())->toBe(2);
});

it('fails when a forced step id is unknown', function (): void {
    artisanCommand('capell:upgrade', [
        '--force' => true,
        '--no-clear-cache' => true,
        '--force-step' => ['core.missing-step'],
    ])
        ->expectsOutputToContain('Unknown forced step id')
        ->assertFailed();
});

it('fails the pipeline when a scheduled step has an unsatisfied dependency', function (): void {
    app()->tag([MissingDependencyStep::class], 'capell.upgrade-steps');

    artisanCommand('capell:upgrade', ['--force' => true, '--skip-migrations' => true, '--no-clear-cache' => true])
        ->expectsOutputToContain('Missing dependency')
        ->assertFailed();

    expect(UpgradeLogEntry::query()->versionSnapshots()->count())->toBe(0);
});

it('does not record versions when a package upgrade command fails', function (): void {
    $packagePath = realpath(__DIR__ . '/../../fixtures/failing-upgrade-package');

    expect($packagePath)->toBeString();

    CapellCore::registerPackage(
        name: 'vendor/failing-upgrade-package',
        path: $packagePath,
    );

    artisanCommand('capell:upgrade', ['--force' => true, '--no-clear-cache' => true])
        ->expectsOutputToContain('per-package upgrade commands failed')
        ->assertFailed();

    $lock = Cache::lock('capell:upgrade', 1);

    try {
        expect(UpgradeLogEntry::query()->versionSnapshots()->count())->toBe(0)
            ->and($lock->get())->toBeTrue();
    } finally {
        $lock->release();
    }
});

it('skips cache clearing by default instead of waiting for a late selection', function (): void {
    artisanCommand('capell:upgrade', ['--skip-steps' => true])
        ->expectsOutputToContain('No cache selection provided; skipping cache clearing.')
        ->assertSuccessful();
});

it('clears all caches when forced without an explicit cache selection', function (): void {
    artisanCommand('capell:upgrade', ['--force' => true])
        ->doesntExpectOutputToContain('No cache selection provided; skipping cache clearing.')
        ->assertSuccessful();
});
