<?php

declare(strict_types=1);

use Capell\Core\Actions\Diagnostics\ResolvePendingMigrationsAction;
use Capell\Core\Data\Install\InstallStoppedStepData;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Support\Diagnostics\Checks\InstallLifecycleCheck;
use Capell\Core\Support\Diagnostics\Checks\MigrationsCheck;
use Capell\Core\Support\Install\InstallRunState;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

function markInstallPartial(): void
{
    CapellExtension::query()->where('composer_name', 'capell-app/core')->delete();
    CapellCore::clearExtensionCache();
}

function markInstallComplete(): void
{
    CapellExtension::query()->updateOrCreate(
        ['composer_name' => 'capell-app/core'],
        ['name' => 'Capell Core', 'status' => ExtensionStatusEnum::Enabled, 'installed_at' => now(), 'is_paid_marketplace_extension' => false],
    );
    CapellCore::clearExtensionCache();
}

function markInstallAbsent(): void
{
    markInstallPartial();
    DB::table('migrations')->where('migration', 'like', '%create_sites_table')->orWhere('migration', 'like', '%create_pages_table')->orWhere('migration', 'like', '%create_capell_extensions_table')->delete();
}

function withPendingMigration(Closure $callback): void
{
    $directory = storage_path('framework/testing/pending-migrations-' . uniqid());
    File::ensureDirectoryExists($directory);
    File::put($directory . '/2099_01_01_000000_create_pending_probe_table.php', "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\n\nreturn new class extends Migration\n{\n    public function up(): void {}\n};\n");
    $migrator = resolve(Migrator::class);
    $migrator->path($directory);

    try {
        $callback($migrator, $directory);
    } finally {
        $migrator->getRepository()->delete((object) ['migration' => '2099_01_01_000000_create_pending_probe_table']);
        File::deleteDirectory($directory);
    }
}

beforeEach(function (): void {
    // The Testbench harness ships migrations of its own that it never runs; a healthy install has none pending.
    $migrations = resolve(Migrator::class)->getRepository();
    foreach (ResolvePendingMigrationsAction::run() as $migration) {
        $migrations->log($migration, $migrations->getNextBatchNumber());
    }

    InstallRunState::clearStoppedStep();
});

afterEach(function (): void {
    InstallRunState::clearStoppedStep();
});

it('names the stopped install step and the exact command that resumes it', function (): void {
    markInstallPartial();
    InstallRunState::recordStoppedStep(new InstallStoppedStepData('run-migrations-mid', 'Run package migrations', "php artisan capell:install --no-interaction --packages='capell-app/admin'"));

    $result = resolve(InstallLifecycleCheck::class)->check();

    expect($result->passed)->toBeFalse()
        ->and($result->id)->toBe('core.install.lifecycle')
        ->and($result->message)->toContain('Run package migrations')
        ->and($result->message)->toContain('run-migrations-mid')
        ->and($result->remediation)->toContain("php artisan capell:install --no-interaction --packages='capell-app/admin'")
        ->and($result->remediation)->not->toContain('migrate')
        ->and($result->evidence['stopped_step']['key'])->toBe('run-migrations-mid');
});

it('says so when an incomplete install did not record which step stopped', function (): void {
    markInstallPartial();

    $result = resolve(InstallLifecycleCheck::class)->check();

    expect($result->passed)->toBeFalse()
        ->and($result->message)->toContain('did not record which step stopped')
        ->and($result->remediation)->toContain('php artisan capell:install')
        ->and($result->remediation)->not->toContain('artisan migrate');
});

it('points an application without Capell at the installer', function (): void {
    markInstallAbsent();
    InstallRunState::recordStoppedStep(new InstallStoppedStepData('stale-step', 'A step from an earlier install', 'php artisan capell:install --stale'));

    $result = resolve(InstallLifecycleCheck::class)->check();

    expect($result->passed)->toBeFalse()
        ->and($result->remediation)->toBe('Run php artisan capell:install.')
        ->and($result->message)->not->toContain('earlier install');
});

it('passes once the installer recorded a complete installation', function (): void {
    markInstallComplete();
    InstallRunState::recordStoppedStep(new InstallStoppedStepData('stale-step', 'A step from an earlier install', 'php artisan capell:install'));

    expect(resolve(InstallLifecycleCheck::class)->check()->passed)->toBeTrue();
});

it('lets the install summary pass while the final installer step is still to come', function (): void {
    markInstallPartial();

    expect(resolve(InstallLifecycleCheck::class)->check(installSummary: true)->passed)->toBeTrue();

    Schema::shouldReceive('hasTable')->andReturnUsing(static fn (string $table): bool => $table !== 'sites')->byDefault();
    Schema::shouldReceive('hasColumn')->andReturnTrue()->byDefault();

    expect(resolve(InstallLifecycleCheck::class)->check(installSummary: true)->passed)->toBeFalse();
});

it('suggests migrate only when migrations are pending', function (): void {
    withPendingMigration(function (): void {
        $result = resolve(MigrationsCheck::class)->check();

        expect($result->passed)->toBeFalse()
            ->and($result->id)->toBe('core.migrations')
            ->and($result->message)->toContain('2099_01_01_000000_create_pending_probe_table')
            ->and($result->remediation)->toBe('Run php artisan migrate.');
    });
});

it('does not suggest migrate when tables are missing but nothing is pending', function (): void {
    Schema::shouldReceive('hasTable')->andReturnUsing(static fn (string $table): bool => $table !== 'sites')->byDefault();
    Schema::shouldReceive('hasColumn')->andReturnTrue()->byDefault();

    $result = resolve(MigrationsCheck::class)->check();

    expect($result->passed)->toBeFalse()
        ->and($result->message)->toContain('sites')
        ->and($result->remediation)->toContain('php artisan capell:install')
        ->and($result->remediation)->not->toContain('artisan migrate');
});

it('passes when no migration is pending and every table exists', function (): void {

    expect(resolve(MigrationsCheck::class)->check()->passed)->toBeTrue();
});

it('lists a migration as pending until it has run', function (): void {
    withPendingMigration(function (Migrator $migrator, string $directory): void {
        expect(ResolvePendingMigrationsAction::run())->toContain('2099_01_01_000000_create_pending_probe_table');

        $migrator->run([$directory]);

        expect(ResolvePendingMigrationsAction::run())->not->toContain('2099_01_01_000000_create_pending_probe_table');
    });
});

it('reports pending migrations first when tables are also missing', function (): void {
    Schema::shouldReceive('hasTable')->andReturnUsing(static fn (string $table): bool => $table !== 'sites')->byDefault();
    Schema::shouldReceive('hasColumn')->andReturnTrue()->byDefault();

    withPendingMigration(function (): void {
        $result = resolve(MigrationsCheck::class)->check();

        expect($result->remediation)->toBe('Run php artisan migrate.')
            ->and($result->evidence['missing_tables'])->toContain('sites');
    });
});
