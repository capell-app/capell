<?php

declare(strict_types=1);

use Capell\Core\Actions\Upgrade\PublishPendingMigrationsAction;
use Capell\Core\Actions\Upgrade\RunDatabaseMigrationsAction;
use Capell\Core\Actions\Upgrade\RunSettingsMigrationsAction;
use Capell\Core\Data\MigrationPublishResult;
use Capell\Core\Data\MigrationRunResult;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Composer\ComposerStateSnapshot;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Marketplace\Actions\RestoreComposerStateAction;
use Capell\Marketplace\Actions\RunPostOperationHealthCheckAction;
use Capell\Marketplace\Contracts\MarketplaceComposerRunner;
use Capell\Marketplace\Data\MarketplaceHealthCheckResultData;
use Capell\Marketplace\Enums\MarketplaceHealthProbeOutcome;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallFailureType;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Enums\MarketplaceOperationType;
use Capell\Marketplace\Jobs\RunMarketplaceUpdateAttemptJob;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Tests\Support\RecordingMarketplaceComposerRunner;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;

uses(CreatesAdminUser::class);

const UPDATE_PACKAGE_NAME = 'capell-app/marketplace-update-package';

/**
 * Register a real-enough package on disk so the job's discovery assertion is
 * exercised rather than stubbed. The registry, not a double, is what decides
 * whether an update is considered applied.
 */
function registerUpdatablePackage(): string
{
    $packagePath = sys_get_temp_dir() . '/capell-marketplace-update-package-' . uniqid();

    File::ensureDirectoryExists($packagePath);
    File::put($packagePath . '/composer.json', json_encode([
        'name' => UPDATE_PACKAGE_NAME,
        'autoload' => ['psr-4' => []],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    CapellCore::registerManifestPackage(CapellManifestData::fromArray(capellManifestV3Array(
        name: UPDATE_PACKAGE_NAME,
        surfaces: ['shared'],
        overrides: [
            'kind' => 'plugin',
            'displayName' => 'Marketplace Update Package',
        ],
    ), $packagePath));

    return $packagePath;
}

function updateAttempt(array $overrides = []): MarketplaceInstallAttempt
{
    return MarketplaceInstallAttempt::query()->create([
        'composer_name' => UPDATE_PACKAGE_NAME,
        'extension_slug' => 'marketplace-update-package',
        'extension_name' => 'Marketplace Update Package',
        'kind' => 'plugin',
        'status' => MarketplaceInstallIntentStatus::Queued,
        'operation' => MarketplaceOperationType::Update,
        'composer_command' => 'composer require ' . UPDATE_PACKAGE_NAME . ':^3.0.0',
        'version_constraint' => '^3.0.0',
        'queued_at' => now(),
        ...$overrides,
    ]);
}

/** @param list<string> $migrationsApplied */
function fakeMigrationPipeline(array $migrationsApplied = [], int $databaseExitCode = 0): void
{
    PublishPendingMigrationsAction::mock()
        ->shouldReceive('handle')
        ->andReturn(new MigrationPublishResult(true, true, ''));

    RunDatabaseMigrationsAction::mock()
        ->shouldReceive('handle')
        ->andReturnUsing(function () use ($migrationsApplied, $databaseExitCode): MigrationRunResult {
            foreach ($migrationsApplied as $migrationName) {
                DB::table('migrations')->insert([
                    'migration' => $migrationName,
                    'batch' => 99,
                ]);
            }

            return new MigrationRunResult($databaseExitCode, 'migrated');
        });

    RunSettingsMigrationsAction::mock()
        ->shouldReceive('handle')
        ->andReturn(new MigrationRunResult(0, 'settings migrated'));
}

function succeedingComposerRunner(): RecordingMarketplaceComposerRunner
{
    $runner = new RecordingMarketplaceComposerRunner('updated');

    app()->instance(MarketplaceComposerRunner::class, $runner);

    return $runner;
}

function failHealthCheck(string $reason): void
{
    app()->instance(RunPostOperationHealthCheckAction::class, new readonly class($reason)
    {
        public function __construct(private string $reason) {}

        public function handle(int $budgetSeconds): MarketplaceHealthCheckResultData
        {
            return new MarketplaceHealthCheckResultData(
                bootProbe: MarketplaceHealthProbeOutcome::Failed,
                httpProbe: MarketplaceHealthProbeOutcome::Skipped,
                failureReason: $this->reason,
            );
        }
    });
}

/**
 * Keep the real recovery `composer install` out of the suite. Bound as an
 * instance rather than mocked because RestoreComposerStateAction is final.
 */
function fakeComposerRestore(): void
{
    app()->instance(RestoreComposerStateAction::class, new class
    {
        public function handle(ComposerStateSnapshot $snapshot, int $timeoutSeconds = 300): bool
        {
            return true;
        }
    });
}

it('passes the new version constraint to composer and finishes the update', function (): void {
    Notification::fake();
    test()->createUserWithRole('super_admin');

    $packagePath = registerUpdatablePackage();
    fakeMigrationPipeline(['2026_08_05_000900_update_package_change']);
    $composer = succeedingComposerRunner();
    $attempt = updateAttempt();

    try {
        new RunMarketplaceUpdateAttemptJob((int) $attempt->getKey())->handle(resolve(MarketplaceComposerRunner::class));
    } finally {
        File::deleteDirectory($packagePath);
    }

    $attempt->refresh();

    expect($composer->calls)->toBe([['name' => UPDATE_PACKAGE_NAME, 'constraint' => '^3.0.0']])
        ->and($attempt->status)->toBe(MarketplaceInstallIntentStatus::Succeeded)
        ->and($attempt->operation)->toBe(MarketplaceOperationType::Update)
        ->and($attempt->failure_reason)->toBeNull()
        ->and($attempt->events()->where('message', __('capell-marketplace::marketplace.operations.timeline_migrations_completed'))->exists())->toBeTrue()
        ->and($attempt->events()->where('message', __('capell-marketplace::marketplace.operations.timeline_notification_sent'))->exists())->toBeTrue();
});

it('never reuses an already downloaded package, because that is the version being replaced', function (): void {
    Notification::fake();
    $packagePath = registerUpdatablePackage();
    fakeMigrationPipeline();
    $composer = succeedingComposerRunner();
    $attempt = updateAttempt();

    try {
        new RunMarketplaceUpdateAttemptJob((int) $attempt->getKey())->handle(resolve(MarketplaceComposerRunner::class));
    } finally {
        File::deleteDirectory($packagePath);
    }

    expect($composer->calls)->toHaveCount(1)
        ->and($attempt->refresh()->events()
            ->where('message', __('capell-marketplace::marketplace.operations.timeline_composer_skipped_downloaded'))
            ->exists())->toBeFalse();
});

it('restores the previous version when the health check fails and no migration ran', function (): void {
    Notification::fake();
    $packagePath = registerUpdatablePackage();
    fakeMigrationPipeline();
    fakeComposerRestore();
    succeedingComposerRunner();
    failHealthCheck('The site did not boot after the update.');
    $attempt = updateAttempt();

    try {
        new RunMarketplaceUpdateAttemptJob((int) $attempt->getKey())->handle(resolve(MarketplaceComposerRunner::class));
    } finally {
        File::deleteDirectory($packagePath);
    }

    $attempt->refresh();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Failed)
        ->and($attempt->failure_stage)->toBe(MarketplaceInstallFailureStage::HealthCheck->value)
        ->and($attempt->failure_type)->toBe(MarketplaceInstallFailureType::HealthCheckFailed->value)
        // Nothing migrated, so the composer rollback really did put the site
        // back and the operator is told exactly that and nothing more.
        ->and($attempt->failure_reason)->toBe('The site did not boot after the update.')
        ->and($attempt->events()->where('message', __('capell-marketplace::marketplace.operations.timeline_rollback_completed'))->exists())->toBeTrue()
        ->and($attempt->events()->where('message', __('capell-marketplace::marketplace.operations.timeline_rollback_schema_retained'))->exists())->toBeFalse();
});

it('tells the operator the schema was not rolled back when the health check fails after migrating', function (): void {
    Notification::fake();
    $packagePath = registerUpdatablePackage();
    fakeMigrationPipeline(['2026_08_05_000901_update_package_adds_a_column']);
    fakeComposerRestore();
    succeedingComposerRunner();
    failHealthCheck('The site did not boot after the update.');
    $attempt = updateAttempt();

    try {
        new RunMarketplaceUpdateAttemptJob((int) $attempt->getKey())->handle(resolve(MarketplaceComposerRunner::class));
    } finally {
        File::deleteDirectory($packagePath);
    }

    $attempt->refresh();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Failed)
        ->and($attempt->failure_stage)->toBe(MarketplaceInstallFailureStage::HealthCheck->value)
        // The distinct type is the point: "health check failed" would send the
        // operator to look at the extension, when what actually needs attention
        // is a database the rollback could not touch.
        ->and($attempt->failure_type)->toBe(MarketplaceInstallFailureType::SchemaAheadOfCode->value)
        ->and($attempt->failure_reason)->toContain('were NOT undone')
        // The cause is never lost, it just stops being the headline.
        ->and($attempt->failure_reason)->toContain('The site did not boot after the update.')
        ->and($attempt->events()->where('message', __('capell-marketplace::marketplace.operations.timeline_rollback_schema_retained'))->exists())->toBeTrue();

    $schemaEvent = $attempt->events()
        ->where('message', __('capell-marketplace::marketplace.operations.timeline_rollback_schema_retained'))
        ->firstOrFail();

    expect($schemaEvent->context['schema_retained'] ?? null)->toBeTrue()
        ->and($schemaEvent->context['applied_migrations'] ?? [])
        ->toContain('2026_08_05_000901_update_package_adds_a_column');
});

it('fails on the migration stage when the migrations themselves fail', function (): void {
    Notification::fake();
    $packagePath = registerUpdatablePackage();
    fakeMigrationPipeline(databaseExitCode: 1);
    fakeComposerRestore();
    succeedingComposerRunner();
    $attempt = updateAttempt();

    try {
        new RunMarketplaceUpdateAttemptJob((int) $attempt->getKey())->handle(resolve(MarketplaceComposerRunner::class));
    } finally {
        File::deleteDirectory($packagePath);
    }

    $attempt->refresh();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Failed)
        ->and($attempt->failure_stage)->toBe(MarketplaceInstallFailureStage::Migration->value)
        ->and($attempt->failure_type)->toBe(MarketplaceInstallFailureType::MigrationFailed->value)
        ->and($attempt->failure_reason)->toContain('database migrations for this update exited 1');
});

it('reports a partially applied migration batch as a schema that stayed ahead', function (): void {
    Notification::fake();
    $packagePath = registerUpdatablePackage();
    fakeMigrationPipeline(['2026_08_05_000902_half_of_the_batch'], databaseExitCode: 1);
    fakeComposerRestore();
    succeedingComposerRunner();
    $attempt = updateAttempt();

    try {
        new RunMarketplaceUpdateAttemptJob((int) $attempt->getKey())->handle(resolve(MarketplaceComposerRunner::class));
    } finally {
        File::deleteDirectory($packagePath);
    }

    $attempt->refresh();

    expect($attempt->failure_stage)->toBe(MarketplaceInstallFailureStage::Migration->value)
        ->and($attempt->failure_type)->toBe(MarketplaceInstallFailureType::SchemaAheadOfCode->value)
        ->and($attempt->failure_reason)->toContain('were NOT undone');
});

it('fails an update whose package is no longer discoverable after composer ran', function (): void {
    Notification::fake();
    fakeMigrationPipeline();
    fakeComposerRestore();
    succeedingComposerRunner();

    $attempt = updateAttempt(['composer_name' => 'capell-app/never-registered-update-package']);

    new RunMarketplaceUpdateAttemptJob((int) $attempt->getKey())->handle(resolve(MarketplaceComposerRunner::class));

    $attempt->refresh();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Failed)
        ->and($attempt->failure_stage)->toBe(MarketplaceInstallFailureStage::PackageDiscovery->value)
        ->and($attempt->failure_reason)->toContain('was not discovered by Capell');
});
