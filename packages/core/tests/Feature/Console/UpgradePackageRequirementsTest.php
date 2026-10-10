<?php

declare(strict_types=1);

use Capell\Core\Actions\Upgrade\PublishPendingMigrationsAction;
use Capell\Core\Actions\Upgrade\RunCapellUpgradeAction;
use Capell\Core\Actions\Upgrade\RunDatabaseMigrationsAction;
use Capell\Core\Actions\Upgrade\RunPublishedDatabaseMigrationsAction;
use Capell\Core\Actions\Upgrade\RunSettingsMigrationsAction;
use Capell\Core\Data\MigrationPublishResult;
use Capell\Core\Data\MigrationRunResult;
use Capell\Core\Data\UpgradeRunOptions;
use Capell\Core\Enums\ExtensionProviderRecoveryStateEnum;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Models\UpgradeLogEntry;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\Packages\InstalledRuntimeLifecycle;
use Capell\Core\Tests\Feature\Console\Fixtures\CmdTrackedStep;
use Capell\Core\Tests\Support\Install\RecordingConsoleKernel;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/** @param list<string> $requires */
function registerUpgradeRequirementFixture(string $name, array $requires = [], ?string $installCommand = null, bool $schemaMigrations = false): void
{
    CapellCore::registerManifestPackage(CapellManifestData::fromArray(capellManifestV3Array(name: $name, overrides: [
        'dependencies' => ['requires' => $requires],
        'commands' => ['install' => $installCommand],
        'database' => ['migrations' => $schemaMigrations, 'settings' => false, 'requiredTables' => []],
    ])));
}

function runUpgradeRequirementFixture(bool $dryRun = false): int
{
    return RunCapellUpgradeAction::run(new UpgradeRunOptions(
        dryRun: $dryRun,
        noClearCache: true,
        skipMigrations: true,
        skipSteps: true,
        onlySteps: true,
        interactive: false,
    ));
}

it('reconciles a requirement added after the dependent was installed without rerunning an installed lifecycle', function (): void {
    registerUpgradeRequirementFixture('capell-app/requirement-fixture', installCommand: 'fixture:install');
    registerUpgradeRequirementFixture('vendor/enabled-dependent');
    CapellCore::markPackageInstalled('capell-app/requirement-fixture');
    CapellCore::markPackageDisabled('capell-app/requirement-fixture');
    CapellCore::markPackageInstalled('vendor/enabled-dependent');
    registerUpgradeRequirementFixture('vendor/enabled-dependent', ['capell-app/requirement-fixture']);
    $kernel = RecordingConsoleKernel::bind(['fixture:install' => true]);

    try {
        expect(runUpgradeRequirementFixture())->toBe(Command::SUCCESS)
            ->and(CapellCore::isPackageEnabled('capell-app/requirement-fixture'))->toBeTrue()
            ->and(array_column($kernel->calls, 'command'))->not->toContain('fixture:install');
    } finally {
        RecordingConsoleKernel::release();
    }
});

it('reconciles disabled requirements through the non-interactive upgrade command even in a partial run', function (): void {
    registerUpgradeRequirementFixture('capell-app/requirement-fixture');
    registerUpgradeRequirementFixture('vendor/enabled-dependent', ['capell-app/requirement-fixture']);
    CapellCore::markPackageInstalled('capell-app/requirement-fixture');
    CapellCore::markPackageDisabled('capell-app/requirement-fixture');
    CapellCore::markPackageInstalled('vendor/enabled-dependent');

    artisanCommand('capell:upgrade', [
        '--force' => true,
        '--no-interaction' => true,
        '--skip-migrations' => true,
        '--skip-steps' => true,
        '--no-clear-cache' => true,
    ])->assertSuccessful();

    expect(CapellCore::isPackageEnabled('capell-app/requirement-fixture'))->toBeTrue();
});

it('installs new transitive first-party requirements once in dependency order without interaction', function (): void {
    registerUpgradeRequirementFixture('capell-app/leaf-fixture', installCommand: 'fixture:install-leaf');
    registerUpgradeRequirementFixture('capell-app/middle-fixture', ['capell-app/leaf-fixture'], 'fixture:install-middle');
    registerUpgradeRequirementFixture('vendor/enabled-dependent', ['capell-app/middle-fixture']);
    CapellCore::markPackageInstalled('vendor/enabled-dependent');
    $kernel = RecordingConsoleKernel::bind(['fixture:install-leaf' => true, 'fixture:install-middle' => true]);

    try {
        expect(runUpgradeRequirementFixture())->toBe(Command::SUCCESS)
            ->and(runUpgradeRequirementFixture())->toBe(Command::SUCCESS)
            ->and(CapellCore::isPackageEnabled('capell-app/leaf-fixture'))->toBeTrue()
            ->and(CapellCore::isPackageEnabled('capell-app/middle-fixture'))->toBeTrue()
            ->and(array_values(array_filter($kernel->calls, fn (array $call): bool => str_starts_with($call['command'], 'fixture:install-'))))->toBe([
                ['command' => 'fixture:install-leaf', 'parameters' => ['--no-interaction' => true]],
                ['command' => 'fixture:install-middle', 'parameters' => ['--no-interaction' => true]],
            ]);
    } finally {
        RecordingConsoleKernel::release();
    }
});

it('fails the command before upgrade work and names a requirement that cannot be repaired automatically', function (string $requirement, bool $registered, bool $interactive): void {
    if ($registered) {
        registerUpgradeRequirementFixture($requirement);
        CapellCore::markPackageInstalled($requirement);
        CapellCore::markPackageDisabled($requirement);
    }

    registerUpgradeRequirementFixture('vendor/enabled-dependent', [$requirement]);
    CapellCore::markPackageInstalled('vendor/enabled-dependent');

    $options = [
        '--force' => true,
        '--no-clear-cache' => true,
    ];
    if (! $interactive) {
        $options['--no-interaction'] = true;
    }

    artisanCommand('capell:upgrade', $options)->expectsOutputToContain('Package [vendor/enabled-dependent] requires [' . $requirement . ']')
        ->assertFailed();

    expect(UpgradeLogEntry::query()->count())->toBe(0);
})->with([
    'disabled third-party' => ['vendor/disabled-requirement', true, false],
    'absent first-party source' => ['capell-app/absent-requirement', false, false],
    'interactive first-party' => ['capell-app/interactive-requirement', true, true],
]);

it('validates the whole requirement closure before enabling anything', function (): void {
    registerUpgradeRequirementFixture('capell-app/repairable-fixture');
    registerUpgradeRequirementFixture('vendor/enabled-dependent', ['capell-app/repairable-fixture', 'vendor/absent-requirement']);
    CapellCore::markPackageInstalled('vendor/enabled-dependent');

    expect(runUpgradeRequirementFixture())->toBe(Command::FAILURE)
        ->and(CapellCore::isPackageEnabled('capell-app/repairable-fixture'))->toBeFalse();
});

it('reports failed requirement installation instead of completing the upgrade', function (): void {
    registerUpgradeRequirementFixture('capell-app/failing-fixture', installCommand: 'fixture:install');
    registerUpgradeRequirementFixture('vendor/enabled-dependent', ['capell-app/failing-fixture']);
    CapellCore::markPackageInstalled('vendor/enabled-dependent');
    Artisan::command('fixture:install', fn (): int => Command::FAILURE);

    artisanCommand('capell:upgrade', ['--force' => true, '--no-interaction' => true, '--no-clear-cache' => true, '--skip-migrations' => true])
        ->expectsOutputToContain("Could not install or enable requirement [capell-app/failing-fixture] for package [vendor/enabled-dependent]: Install command 'fixture:install' failed with exit code 1.")
        ->assertFailed();

    expect(CapellCore::isPackageEnabled('capell-app/failing-fixture'))->toBeFalse();
});

it('reports unmet requirements in a command dry run without enabling them', function (): void {
    registerUpgradeRequirementFixture('capell-app/requirement-fixture');
    registerUpgradeRequirementFixture('vendor/enabled-dependent', ['capell-app/requirement-fixture']);
    CapellCore::markPackageInstalled('vendor/enabled-dependent');

    artisanCommand('capell:upgrade', ['--dry-run' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Package [vendor/enabled-dependent] requires [capell-app/requirement-fixture]')
        ->assertSuccessful();

    expect(CapellCore::isPackageEnabled('capell-app/requirement-fixture'))->toBeFalse()
        ->and(UpgradeLogEntry::query()->count())->toBe(0);
});

it('migrates the existing schema before a new requirement runs its install lifecycle', function (): void {
    registerUpgradeRequirementFixture('capell-app/requirement-fixture', installCommand: 'fixture:install');
    registerUpgradeRequirementFixture('vendor/enabled-dependent', ['capell-app/requirement-fixture']);
    CapellCore::markPackageInstalled('vendor/enabled-dependent');
    Artisan::command('fixture:install', fn (): int => Schema::hasTable('upgrade_requirement_ready') ? Command::SUCCESS : Command::FAILURE);

    PublishPendingMigrationsAction::shouldRun()->once()->andReturn(new MigrationPublishResult(true, true, ''));
    RunDatabaseMigrationsAction::shouldRun()->once()->andReturnUsing(function (): MigrationRunResult {
        Schema::create('upgrade_requirement_ready', function (Blueprint $table): void {
            $table->id();
        });

        return new MigrationRunResult(Command::SUCCESS, '');
    });
    RunPublishedDatabaseMigrationsAction::shouldRun()->once()->andReturn(new MigrationRunResult(Command::SUCCESS, ''));
    RunSettingsMigrationsAction::shouldRun()->once()->andReturn(new MigrationRunResult(Command::SUCCESS, ''));

    expect(RunCapellUpgradeAction::run(new UpgradeRunOptions(
        noClearCache: true,
        skipSteps: true,
        interactive: false,
    )))->toBe(Command::SUCCESS)
        ->and(CapellCore::isPackageEnabled('capell-app/requirement-fixture'))->toBeTrue();
});

it('runs upgrade steps registered by a newly installed requirement in the same upgrade', function (): void {
    registerUpgradeRequirementFixture('capell-app/requirement-fixture', installCommand: 'fixture:install');
    registerUpgradeRequirementFixture('vendor/enabled-dependent', ['capell-app/requirement-fixture']);
    CapellCore::markPackageInstalled('vendor/enabled-dependent');
    CmdTrackedStep::$runs = 0;
    Artisan::command('fixture:install', function (): int {
        app()->tag([CmdTrackedStep::class], 'capell.upgrade-steps');

        return Command::SUCCESS;
    });

    expect(RunCapellUpgradeAction::run(new UpgradeRunOptions(
        noClearCache: true,
        skipMigrations: true,
        interactive: false,
    )))->toBe(Command::SUCCESS)
        ->and(CmdTrackedStep::$runs)->toBe(1);
});

it('migrates a disabled installed requirement before activating its runtime', function (): void {
    $root = sys_get_temp_dir() . '/capell-upgrade-requirement-' . bin2hex(random_bytes(8));
    $originalDatabasePath = database_path();
    File::ensureDirectoryExists($root . '/database/migrations');
    File::ensureDirectoryExists($root . '/requirement/database/migrations');
    File::put($root . '/requirement/database/migrations/2099_01_01_000001_requirement_ready.php', <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::create('upgrade_requirement_ready', function (Blueprint $table): void {
                    $table->id();
                });
            }

            public function down(): void
            {
                Schema::dropIfExists('upgrade_requirement_ready');
            }
        };
        PHP);
    app()->useDatabasePath($root . '/database');
    registerUpgradeRequirementFixture('capell-app/requirement-fixture', schemaMigrations: true);
    CapellCore::getPackage('capell-app/requirement-fixture')->path = $root . '/requirement';
    registerUpgradeRequirementFixture('vendor/enabled-dependent', ['capell-app/requirement-fixture']);
    CapellCore::markPackageInstalled('capell-app/requirement-fixture');
    CapellCore::markPackageDisabled('capell-app/requirement-fixture');
    CapellCore::markPackageInstalled('vendor/enabled-dependent');
    $activated = false;
    resolve(InstalledRuntimeLifecycle::class)->register(CmdTrackedStep::class, 'capell-app/requirement-fixture', 'runtime', function () use (&$activated): void {
        expect(Schema::hasTable('upgrade_requirement_ready'))->toBeTrue();
        $activated = true;
    });
    try {
        expect(runUpgradeRequirementFixture())->toBe(Command::SUCCESS)
            ->and($activated)->toBeTrue();
    } finally {
        app()->useDatabasePath($originalDatabasePath);
        File::deleteDirectory($root);
    }
});

it('repairs requirements of enabled dependencies as well as the original enabled package', function (): void {
    registerUpgradeRequirementFixture('capell-app/leaf-fixture');
    registerUpgradeRequirementFixture('vendor/enabled-middle', ['capell-app/leaf-fixture']);
    registerUpgradeRequirementFixture('vendor/enabled-dependent', ['vendor/enabled-middle']);
    CapellCore::markPackageInstalled('vendor/enabled-middle');
    CapellCore::markPackageInstalled('vendor/enabled-dependent');

    expect(runUpgradeRequirementFixture())->toBe(Command::SUCCESS)
        ->and(CapellCore::isPackageEnabled('capell-app/leaf-fixture'))->toBeTrue();
});

it('does not automatically override failed quarantined or entitlement-blocked requirements', function (string $state): void {
    registerUpgradeRequirementFixture('capell-app/blocked-fixture');
    registerUpgradeRequirementFixture('vendor/enabled-dependent', ['capell-app/blocked-fixture']);
    CapellCore::markPackageInstalled('vendor/enabled-dependent');
    CapellExtension::query()->create([
        'composer_name' => 'capell-app/blocked-fixture',
        'status' => $state === 'failed' ? ExtensionStatusEnum::Failed : ExtensionStatusEnum::Disabled,
        'installed_at' => now(),
        'provider_recovery_state' => $state === 'quarantined' ? ExtensionProviderRecoveryStateEnum::Quarantined : ExtensionProviderRecoveryStateEnum::Healthy,
        'marketplace_runtime_status' => $state === 'entitlement' ? 'revoked' : null,
    ]);
    CapellCore::clearExtensionCache();

    expect(runUpgradeRequirementFixture())->toBe(Command::FAILURE)
        ->and(CapellCore::isPackageEnabled('capell-app/blocked-fixture'))->toBeFalse();
})->with(['failed', 'quarantined', 'entitlement']);

it('names circular requirements instead of recursing or starting installations', function (): void {
    registerUpgradeRequirementFixture('vendor/enabled-dependent', ['capell-app/circular-fixture']);
    registerUpgradeRequirementFixture('capell-app/circular-fixture', ['vendor/enabled-dependent']);
    CapellCore::markPackageInstalled('vendor/enabled-dependent');

    artisanCommand('capell:upgrade', ['--force' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Package [capell-app/circular-fixture] has a circular dependency on requirement [vendor/enabled-dependent]')
        ->assertFailed();

    expect(CapellCore::isPackageEnabled('capell-app/circular-fixture'))->toBeFalse();
});

it('does not install requirements of disabled dependents or optional supporting packages', function (): void {
    registerUpgradeRequirementFixture('capell-app/unused-fixture');
    registerUpgradeRequirementFixture('vendor/disabled-dependent', ['capell-app/unused-fixture']);
    CapellCore::markPackageDisabled('vendor/disabled-dependent');
    registerUpgradeRequirementFixture('vendor/enabled-dependent');
    CapellCore::getPackage('vendor/enabled-dependent')->supportingPackages = ['capell-app/unused-fixture'];
    CapellCore::markPackageInstalled('vendor/enabled-dependent');

    expect(runUpgradeRequirementFixture())->toBe(Command::SUCCESS)
        ->and(CapellCore::isPackageEnabled('capell-app/unused-fixture'))->toBeFalse();
});
