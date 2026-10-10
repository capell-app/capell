<?php

declare(strict_types=1);

use Capell\Core\Actions\Upgrade\RunDatabaseMigrationsAction;
use Capell\Core\Actions\Upgrade\RunPublishedDatabaseMigrationsAction;
use Capell\Core\Data\MigrationRunResult;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\UpgradeLogEntry;
use Capell\Core\Models\UpgradeRun;
use Capell\Core\Tests\Feature\Console\Fixtures\CmdTrackedStep;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

function withUpgradePackageMigrationFixture(Closure $assertions): void
{
    Schema::create('upgrade_package_migration_order', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    $root = sys_get_temp_dir() . '/capell-upgrade-package-migrations-' . bin2hex(random_bytes(8));
    $originalDatabasePath = database_path();
    File::ensureDirectoryExists($root . '/database/migrations');
    app()->useDatabasePath($root . '/database');

    try {
        $assertions($root);
    } finally {
        app()->useDatabasePath($originalDatabasePath);
        File::deleteDirectory($root);
    }
}

function writeUpgradePackageMigration(string $path, string $name): void
{
    File::ensureDirectoryExists(dirname($path));
    File::put($path, str_replace('__NAME__', $name, <<<'PHP'
        <?php

        declare(strict_types=1);

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Support\Facades\DB;

        return new class extends Migration
        {
            public function up(): void
            {
                DB::table('upgrade_package_migration_order')->insert(['name' => '__NAME__']);
            }

            public function down(): void
            {
                DB::table('upgrade_package_migration_order')->where('name', '__NAME__')->delete();
            }
        };
        PHP));
}

it('upgrades host and installed enabled package migrations in order exactly once before settings', function (): void {
    withUpgradePackageMigrationFixture(function (string $root): void {
        writeUpgradePackageMigration($root . '/database/migrations/2099_01_01_000001_host_upgrade.php', 'host');
        writeUpgradePackageMigration($root . '/dependent/database/migrations/2099_01_01_000003_package_upgrade.php.stub', 'dependent');
        writeUpgradePackageMigration($root . '/dependency/database/migrations/2099_01_01_000002_dependency_upgrade.php', 'dependency');

        foreach (['dependent', 'dependency'] as $package) {
            CapellCore::forcePackageInstalled('vendor/upgrade-' . $package);
            CapellCore::registerPackage('vendor/upgrade-' . $package, path: $root . '/' . $package);

            expect(CapellCore::isPackageInstalled('vendor/upgrade-' . $package))->toBeTrue()
                ->and(CapellCore::isPackageEnabled('vendor/upgrade-' . $package))->toBeTrue();
        }

        Artisan::command('settings:migrate {--force}', function (): int {
            expect(DB::table('upgrade_package_migration_order')->orderBy('id')->pluck('name')->all())
                ->toBe(['host', 'dependency', 'dependent']);

            return Command::SUCCESS;
        });

        for ($run = 0; $run < 2; $run++) {
            artisanCommand('capell:upgrade', [
                '--force' => true,
                '--no-clear-cache' => true,
            ])->expectsOutputToContain('  migrate exit=0')->assertSuccessful();
        }

        expect(DB::table('upgrade_package_migration_order')->orderBy('id')->pluck('name')->all())
            ->toBe(['host', 'dependency', 'dependent'])
            ->and(DB::table('migrations')->where('migration', 'like', '2099_01_01_%')->count())->toBe(3);
    });
});

it('previews the same pending host and package migrations that apply runs', function (): void {
    withUpgradePackageMigrationFixture(function (string $root): void {
        $expected = [
            '2099_01_01_000001_host_preview',
            '2099_01_01_000002_published_preview',
            '2099_01_01_000003_package_preview',
        ];
        writeUpgradePackageMigration($root . '/database/migrations/' . $expected[0] . '.php', 'host');
        writeUpgradePackageMigration($root . '/database/migrations/' . $expected[1] . '.php', 'published');
        writeUpgradePackageMigration($root . '/installed/database/migrations/' . $expected[2] . '.php.stub', 'package');
        File::put($root . '/installed/database/migrations/helper.php', "<?php throw new RuntimeException('Not a migration.');");
        CapellCore::forcePackageInstalled('vendor/upgrade-preview');
        CapellCore::registerPackage('vendor/upgrade-preview', path: $root . '/installed');

        $alreadyRan = '2099_01_01_000004_already_ran';
        writeUpgradePackageMigration($root . '/database/migrations/' . $alreadyRan . '.php', 'already ran');
        DB::table('migrations')->insert(['migration' => $alreadyRan, 'batch' => 1]);
        writeUpgradePackageMigration($root . '/uninstalled/database/migrations/2099_01_01_000005_uninstalled_preview.php', 'uninstalled');
        CapellCore::registerPackage('vendor/upgrade-uninstalled-preview', path: $root . '/uninstalled');
        // Record the state explicitly: an unrecorded package falls back to shared lifecycle state.
        CapellCore::forcePackageInstalled('vendor/upgrade-uninstalled-preview', false);
        resolve('migrator')->path($root . '/uninstalled/database/migrations');

        $pendingCore = '2026_07_22_000003_create_metric_events_table';
        Schema::drop('metric_events');
        DB::table('migrations')->where('migration', $pendingCore)->delete();
        writeUpgradePackageMigration($root . '/database/migrations/2099_01_01_000006_create_metric_events_table.php', 'redundant core copy');

        expect(Artisan::call('capell:upgrade', ['--dry-run' => true]))->toBe(Command::SUCCESS);
        preg_match_all('/^\s+(2099_01_01_\w+|2026_07_22_000003_create_metric_events_table)\s*$/m', Artisan::output(), $matches);
        $previewed = $matches[1];

        expect(Artisan::output())->not->toContain('  helper');
        expect($previewed)->toBe([$pendingCore, ...$expected])
            ->and(File::exists(database_path('migrations/' . $expected[2] . '.php')))->toBeFalse()
            ->and(Schema::hasTable('metric_events'))->toBeFalse()
            ->and(DB::table('upgrade_package_migration_order')->count())->toBe(0);

        Artisan::command('settings:migrate {--force}', fn (): int => Command::SUCCESS);
        artisanCommand('capell:upgrade', ['--force' => true, '--no-clear-cache' => true])->assertSuccessful();

        $applied = DB::table('migrations')->where('migration', 'like', '2099_01_01_%')
            ->where('migration', '!=', $alreadyRan)->orderBy('migration')->pluck('migration')->all();

        expect($applied)->toBe($expected)->and($previewed)->toBe([$pendingCore, ...$applied])
            ->and(DB::table('migrations')->where('migration', $pendingCore)->count())->toBe(1)
            ->and(Schema::hasTable('metric_events'))->toBeTrue()
            ->and(DB::table('upgrade_package_migration_order')->orderBy('id')->pluck('name')->all())
            ->toBe(['host', 'published', 'package']);
    });
});

it('does not publish or run migrations from an uninstalled package even when its path is registered', function (): void {
    withUpgradePackageMigrationFixture(function (string $root): void {
        writeUpgradePackageMigration($root . '/installed/database/migrations/2099_01_01_000003_installed_upgrade.php', 'installed');
        CapellCore::forcePackageInstalled('vendor/upgrade-installed');
        CapellCore::registerPackage('vendor/upgrade-installed', path: $root . '/installed');

        $sourcePath = $root . '/uninstalled/database/migrations';
        $migrationName = '2099_01_01_000004_uninstalled_upgrade';
        writeUpgradePackageMigration($sourcePath . '/' . $migrationName . '.php', 'uninstalled');
        CapellCore::registerPackage('vendor/upgrade-uninstalled', path: $root . '/uninstalled');

        /** @var Migrator $migrator */
        $migrator = resolve('migrator');
        $migrator->path($sourcePath);

        expect(CapellCore::isPackageInstalled('vendor/upgrade-uninstalled'))->toBeFalse();

        Artisan::command('settings:migrate {--force}', fn (): int => Command::SUCCESS);

        artisanCommand('capell:upgrade', [
            '--force' => true,
            '--only-migrations' => true,
            '--no-clear-cache' => true,
        ])->assertSuccessful();

        expect(DB::table('upgrade_package_migration_order')->pluck('name')->all())->toBe(['installed'])
            ->and(DB::table('migrations')->where('migration', $migrationName)->exists())->toBeFalse()
            ->and(File::exists(database_path('migrations/' . $migrationName . '.php')))->toBeFalse();
    });
});

it('keeps package migrations behind downgrade protection unless force-downgrade is supplied', function (): void {
    withUpgradePackageMigrationFixture(function (string $root): void {
        writeUpgradePackageMigration($root . '/installed/database/migrations/2099_01_01_000005_downgrade_upgrade.php', 'installed');
        CapellCore::forcePackageInstalled('vendor/upgrade-installed');
        CapellCore::registerPackage('vendor/upgrade-installed', path: $root . '/installed');
        UpgradeLogEntry::query()->create([
            'type' => 'version_snapshot',
            'key' => 'capell-app/capell',
            'package' => 'capell-app/capell',
            'status' => 'recorded',
            'ran_at' => now()->subDay(),
            'meta' => ['to_version' => '99.0.0'],
        ]);
        Artisan::command('settings:migrate {--force}', fn (): int => Command::SUCCESS);

        artisanCommand('capell:upgrade', [
            '--force' => true,
            '--only-migrations' => true,
            '--no-clear-cache' => true,
        ])->expectsOutputToContain('Downgrade detected')->assertFailed();

        expect(DB::table('upgrade_package_migration_order')->count())->toBe(0)
            ->and(File::exists(database_path('migrations/2099_01_01_000005_downgrade_upgrade.php')))->toBeFalse();

        artisanCommand('capell:upgrade', [
            '--force-downgrade' => true,
            '--only-migrations' => true,
            '--no-clear-cache' => true,
        ])->assertSuccessful();

        expect(DB::table('upgrade_package_migration_order')->pluck('name')->all())->toBe(['installed']);
    });
});

it('reports a failed published migration and stops before upgrade steps and version recording', function (): void {
    CmdTrackedStep::$runs = 0;
    app()->tag([CmdTrackedStep::class], 'capell.upgrade-steps');
    RunPublishedDatabaseMigrationsAction::shouldRun()->once()->with(false)
        ->andReturn(new MigrationRunResult(7, 'Published migration failed'));
    Artisan::command('settings:migrate {--force}', fn (): int => Command::SUCCESS);

    artisanCommand('capell:upgrade', [
        '--force' => true,
        '--no-clear-cache' => true,
    ])->expectsOutputToContain('  migrate exit=7')
        ->expectsOutputToContain('Migration phase failed.')
        ->assertFailed();

    expect(CmdTrackedStep::$runs)->toBe(0)
        ->and(UpgradeLogEntry::query()->versionSnapshots()->count())->toBe(0);
});

it('stops before settings steps and version recording when a schema migration returns nonzero', function (string $phase): void {
    CmdTrackedStep::$runs = 0;
    app()->tag([CmdTrackedStep::class], 'capell.upgrade-steps');
    $settingsRuns = 0;
    Artisan::command('settings:migrate {--force}', function () use (&$settingsRuns): int {
        $settingsRuns++;

        return Command::SUCCESS;
    });

    if ($phase === 'core') {
        RunDatabaseMigrationsAction::shouldRun()->once()->with(false)
            ->andReturn(new MigrationRunResult(7, 'Core migration failed'));
        RunPublishedDatabaseMigrationsAction::shouldRun()->never();
    } else {
        RunPublishedDatabaseMigrationsAction::shouldRun()->once()->with(false)
            ->andReturn(new MigrationRunResult(7, 'Published migration failed'));
    }

    artisanCommand('capell:upgrade', ['--force' => true, '--no-clear-cache' => true])
        ->expectsOutputToContain('  migrate exit=7')
        ->expectsOutputToContain('Migration phase failed.')
        ->assertFailed();

    expect($settingsRuns)->toBe(0)->and(CmdTrackedStep::$runs)->toBe(0)
        ->and(UpgradeLogEntry::query()->versionSnapshots()->count())->toBe(0);
})->with(['core', 'published']);

it('requires production confirmation before running host migrations without force', function (): void {
    withUpgradePackageMigrationFixture(function (string $root): void {
        app()->detectEnvironment(fn (): string => 'production');
        writeUpgradePackageMigration($root . '/database/migrations/2099_01_01_000001_production_upgrade.php', 'host');
        Artisan::command('settings:migrate {--force}', fn (): int => Command::SUCCESS);

        artisanCommand('capell:upgrade', ['--only-migrations' => true, '--no-clear-cache' => true])
            ->expectsConfirmation('Are you sure you want to run this command?', 'no')
            ->assertFailed();

        expect(DB::table('upgrade_package_migration_order')->count())->toBe(0)
            ->and(DB::table('migrations')->where('migration', '2099_01_01_000001_production_upgrade')->exists())->toBeFalse()
            ->and(UpgradeRun::query()->count())->toBe(0);
    });
});

it('runs host migrations in production after confirmation or with force', function (bool $force): void {
    withUpgradePackageMigrationFixture(function (string $root) use ($force): void {
        app()->detectEnvironment(fn (): string => 'production');
        writeUpgradePackageMigration($root . '/database/migrations/2099_01_01_000001_confirmed_upgrade.php', 'host');
        Artisan::command('settings:migrate {--force}', fn (): int => Command::SUCCESS);

        $command = artisanCommand('capell:upgrade', [
            '--force' => $force,
            '--only-migrations' => true,
            '--no-clear-cache' => true,
        ]);

        if (! $force) {
            $command->expectsConfirmation('Are you sure you want to run this command?', 'yes');
        }

        $command->assertSuccessful()->run();

        expect(DB::table('upgrade_package_migration_order')->pluck('name')->all())->toBe(['host'])
            ->and(DB::table('migrations')->where('migration', '2099_01_01_000001_confirmed_upgrade')->exists())->toBeTrue();
    });
})->with([false, true]);

it('refuses noninteractive production upgrades without force', function (): void {
    withUpgradePackageMigrationFixture(function (string $root): void {
        app()->detectEnvironment(fn (): string => 'production');
        writeUpgradePackageMigration($root . '/database/migrations/2099_01_01_000001_noninteractive_upgrade.php', 'host');

        expect(Artisan::call('capell:upgrade', ['--no-interaction' => true, '--no-clear-cache' => true]))
            ->toBe(Command::FAILURE)
            ->and(Artisan::output())->toContain('Command cancelled.');

        expect(DB::table('upgrade_package_migration_order')->count())->toBe(0)
            ->and(UpgradeRun::query()->count())->toBe(0);
    });
});

it('keeps production dry runs read only without confirmation or force', function (): void {
    withUpgradePackageMigrationFixture(function (string $root): void {
        app()->detectEnvironment(fn (): string => 'production');
        writeUpgradePackageMigration($root . '/database/migrations/2099_01_01_000001_production_preview.php', 'host');

        artisanCommand('capell:upgrade', ['--dry-run' => true, '--no-interaction' => true])
            ->expectsOutputToContain('2099_01_01_000001_production_preview')
            ->assertSuccessful();

        expect(DB::table('upgrade_package_migration_order')->count())->toBe(0)
            ->and(UpgradeRun::query()->count())->toBe(0);
    });
});

it('stops before settings steps and versions when a published migration throws', function (): void {
    withUpgradePackageMigrationFixture(function (string $root): void {
        CmdTrackedStep::$runs = 0;
        app()->tag([CmdTrackedStep::class], 'capell.upgrade-steps');
        $settingsRuns = 0;
        Artisan::command('settings:migrate {--force}', function () use (&$settingsRuns): int {
            $settingsRuns++;

            return Command::SUCCESS;
        });
        $path = $root . '/database/migrations/2099_01_01_000001_throwing_upgrade.php';
        writeUpgradePackageMigration($path, 'host');
        File::put($path, str_replace(
            "DB::table('upgrade_package_migration_order')->insert(['name' => 'host']);",
            "throw new RuntimeException('Migration fixture failed.');",
            File::get($path),
        ));

        expect(fn (): int => Artisan::call('capell:upgrade', ['--force' => true, '--no-clear-cache' => true]))
            ->toThrow(RuntimeException::class, 'Migration fixture failed.');

        expect($settingsRuns)->toBe(0)->and(CmdTrackedStep::$runs)->toBe(0)
            ->and(UpgradeLogEntry::query()->versionSnapshots()->count())->toBe(0);
    });
});

it('does not fall back to registered uninstalled sources when no schema migrations are pending', function (): void {
    withUpgradePackageMigrationFixture(function (string $root): void {
        $path = $root . '/uninstalled/database/migrations';
        writeUpgradePackageMigration($path . '/2099_01_01_000001_uninstalled_fallback.php', 'uninstalled');
        CapellCore::registerPackage('vendor/upgrade-uninstalled-fallback', path: $root . '/uninstalled');
        resolve('migrator')->path($path);
        Artisan::command('settings:migrate {--force}', fn (): int => Command::SUCCESS);

        artisanCommand('capell:upgrade', ['--force' => true, '--only-migrations' => true, '--no-clear-cache' => true])
            ->assertSuccessful();

        expect(DB::table('upgrade_package_migration_order')->count())->toBe(0)
            ->and(DB::table('migrations')->where('migration', '2099_01_01_000001_uninstalled_fallback')->exists())->toBeFalse();
    });
});
