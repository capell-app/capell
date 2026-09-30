<?php

declare(strict_types=1);

use Capell\Core\Actions\Upgrade\RunPublishedDatabaseMigrationsAction;
use Capell\Core\Data\MigrationRunResult;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\UpgradeLogEntry;
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
