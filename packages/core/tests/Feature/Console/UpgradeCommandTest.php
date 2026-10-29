<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Feature\Console;

use Capell\Core\Actions\Install\FindTableCollisionsAction;
use Capell\Core\Actions\Upgrade\ReadLatestInstalledVersionsAction;
use Capell\Core\Actions\Upgrade\ResolveAdminViewDirectoryAction;
use Capell\Core\Actions\Upgrade\ResolveInstalledComposerVersionsAction;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\UpgradeLogEntry;
use Capell\Core\Models\UpgradeRun;
use Capell\Core\Models\UpgradeRunEvent;
use Capell\Core\Support\Upgrade\DatabaseUpgradeLock;
use Capell\Core\Tests\Feature\Console\Fixtures\CmdTrackedStep;
use Capell\Core\Tests\Feature\Console\Fixtures\MissingDependencyStep;
use Capell\Core\Tests\Feature\Console\Fixtures\ReportOnlyMutatingStep;
use Capell\Core\Tests\Support\HostMigrationIsolation;
use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->beforeApplicationDestroyed(HostMigrationIsolation::setUp(app()));

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
})->group('database-portability');

it('preserves applied settings while running a pending host migration exactly once', function (): void {
    $applied = '2025_11_20_000000_create_settings_table';
    $pending = '2099_01_01_000001_create_upgrade_command_fixture_table';
    $settings = DB::table('settings')->orderBy('id')->get()->all();
    expect(DB::table('migrations')->where('migration', $applied)->count())->toBe(1);

    File::put(database_path('migrations/' . $applied . '.php'), <<<'PHP'
        <?php

        declare(strict_types=1);

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::create('settings', function (Blueprint $table): void {
                    $table->id();
                });
            }
        };
        PHP);
    File::put(database_path('migrations/' . $pending . '.php'), <<<'PHP'
        <?php

        declare(strict_types=1);

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::create('upgrade_command_fixture', function (Blueprint $table): void {
                    $table->id();
                });
            }
        };
        PHP);

    expect(Schema::hasTable('upgrade_command_fixture'))->toBeFalse();

    for ($run = 0; $run < 2; $run++) {
        artisanCommand('capell:upgrade', ['--force' => true, '--no-clear-cache' => true])->assertSuccessful();

        expect(Schema::hasTable('upgrade_command_fixture'))->toBeTrue()
            ->and(DB::table('settings')->orderBy('id')->get()->all())->toEqual($settings)
            ->and(DB::table('migrations')->where('migration', $applied)->count())->toBe(1)
            ->and(DB::table('migrations')->where('migration', $pending)->count())->toBe(1);
    }
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
        ->assertSuccessful();

    expect(CmdTrackedStep::$runs)->toBe(0)
        ->and(UpgradeLogEntry::query()->count())->toBe(0)
        ->and(UpgradeRun::query()->count())->toBe(0)
        ->and(UpgradeRunEvent::query()->count())->toBe(0);
});

it('reports readiness warnings successfully without creating upgrade state', function (): void {
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
        ->expectsOutputToContain('Published Admin views')
        ->expectsOutputToContain('Backup prerequisite: unknown')
        ->expectsOutputToContain('Migration irreversibility: unknown')
        ->assertSuccessful();

    expect(CmdTrackedStep::$runs)->toBe(0)
        ->and(ReportOnlyMutatingStep::$shouldRunCalls)->toBe(0)
        ->and(UpgradeLogEntry::query()->count())->toBe(0)
        ->and(UpgradeRun::query()->count())->toBe(0)
        ->and(UpgradeRunEvent::query()->count())->toBe(0)
        ->and(file_exists($migrationLockPath))->toBeFalse();

    $cacheSpy->shouldNotHaveReceived('lock');
    Process::assertNothingRan();
});

it('reports the versioned upgrade guidance for the installed range', function (): void {
    ResolveInstalledComposerVersionsAction::mock()->shouldReceive('handle')->andReturn(['capell-app/core' => '1.0.0']);
    UpgradeLogEntry::query()->create([
        'type' => 'version_snapshot', 'key' => 'capell-app/core', 'package' => 'capell-app/core',
        'status' => 'recorded', 'ran_at' => now()->subDay(), 'meta' => ['to_version' => '0.9.0'],
    ]);

    artisanCommand('capell:upgrade', ['--dry-run' => true])
        ->expectsOutputToContain('Versioned upgrade guide')
        ->expectsOutputToContain('Back up the database, environment and storage')
        ->assertSuccessful();
});

it('reports published Admin view differences during a dry run without changing them', function (): void {
    $originalBase = app()->basePath();
    $root = sys_get_temp_dir() . '/capell-upgrade-view-drift-' . bin2hex(random_bytes(8));
    File::makeDirectory($root . '/resources/views/vendor/capell-admin/components', 0755, true);
    File::makeDirectory($root . '/admin/resources/views/components', 0755, true);
    File::put($root . '/resources/views/vendor/capell-admin/components/example.blade.php', 'custom');
    File::put($root . '/admin/resources/views/components/example.blade.php', 'upstream fix');
    ResolveAdminViewDirectoryAction::mock()->shouldReceive('handle')->andReturn($root . '/admin/resources/views');
    app()->setBasePath($root);

    try {
        artisanCommand('capell:upgrade', ['--dry-run' => true])
            ->expectsOutputToContain('Review differing override: components/example.blade.php')
            ->assertSuccessful();
        expect(File::get($root . '/resources/views/vendor/capell-admin/components/example.blade.php'))->toBe('custom');
    } finally {
        app()->setBasePath($originalBase);
        File::deleteDirectory($root);
    }
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
            ->assertSuccessful();
    } finally {
        @unlink($migrationPath);
        @rmdir($migrationDirectory);
        @rmdir($packagePath . '/database');
        @rmdir($packagePath);
    }
});

it('audits manifest declarations without autoloading extension classes', function (): void {
    $originalInstalledVersions = InstalledVersions::getAllRawData()[0];
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
            ->assertSuccessful();

        artisanCommand('capell:upgrade', ['--dry-run' => true, '--strict' => true])
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
        ->assertSuccessful();
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

it('rejects strict mode without a dry run before starting an upgrade', function (): void {
    artisanCommand('capell:upgrade', ['--strict' => true, '--force' => true])
        ->expectsOutputToContain('--strict requires --dry-run.')
        ->assertFailed();

    expect(CmdTrackedStep::$runs)->toBe(0)
        ->and(UpgradeRun::query()->count())->toBe(0);
});

it('recovers an aliased pages migration without adopting the foreign pages table', function (): void {
    config()->set('capell.database.table_aliases.pages', 'content_pages');
    $foreignCount = DB::table('pages')->count();
    $migration = '2026_05_10_190832_12_create_pages_table';
    DB::table('migrations')->where('migration', $migration)->delete();

    artisanCommand('capell:upgrade', ['--force' => true, '--only-migrations' => true, '--no-clear-cache' => true])
        ->assertSuccessful();

    expect(Schema::hasTable('content_pages'))->toBeTrue()
        ->and(DB::table('pages')->count())->toBe($foreignCount)
        ->and(DB::table('migrations')->where('migration', $migration)->exists())->toBeTrue();
});

it('builds an upgrade plan with a prefixed Capell schema', function (): void {
    DB::table('capell_upgrade_log')->insert([
        'type' => 'version_snapshot', 'key' => 'capell-app/core', 'status' => 'success',
        'ran_at' => '2026-01-01 00:00:00', 'meta' => json_encode(['to_version' => '1.0.0']),
    ]);
    $copied = [];
    try {
        foreach (resolve(FindTableCollisionsAction::class)->planned([]) as $table) {
            if (! str_starts_with($table->configKey, 'capell.database.table_aliases.')) {
                continue;
            }

            if (! Schema::hasTable($table->name)) {
                continue;
            }

            $target = 'cms_' . $table->name;
            DB::statement('CREATE TABLE "' . $target . '" AS SELECT * FROM "' . $table->name . '"');
            $copied[] = $target;
        }

        config()->set('capell.database.table_prefix', 'cms_');
        expect(ReadLatestInstalledVersionsAction::run(['capell-app/core']))->toBe(['capell-app/core' => '1.0.0']);
        artisanCommand('capell:upgrade', ['--dry-run' => true, '--force' => true, '--no-clear-cache' => true])->assertSuccessful();
    } finally {
        config()->set('capell.database.table_prefix', '');
        foreach ($copied as $table) {
            Schema::dropIfExists($table);
        }
    }
});
