<?php

declare(strict_types=1);

use Capell\Core\Actions\Upgrade\PublishPendingMigrationsAction;
use Capell\Core\Actions\Upgrade\RunDatabaseMigrationsAction;
use Capell\Core\Actions\Upgrade\RunPublishedDatabaseMigrationsAction;
use Capell\Core\Actions\Upgrade\RunSettingsMigrationsAction;
use Capell\Core\Data\MigrationPublishResult;
use Capell\Core\Data\MigrationRunResult;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Tests\Support\Fixtures\Autoload\LifecycleRecorderAction;
use Capell\Marketplace\Actions\BuildMarketplaceInstallPolicyEvidenceAction;
use Capell\Marketplace\Actions\CreateExtensionAcquisitionAction;
use Capell\Marketplace\Actions\QueueMarketplaceInstallAttemptAction;
use Capell\Marketplace\Actions\QueueMarketplaceUninstallAttemptAction;
use Capell\Marketplace\Actions\ResolveMarketplaceInstallEligibilityAction;
use Capell\Marketplace\Actions\RunPostOperationHealthCheckAction;
use Capell\Marketplace\Actions\UpdateMarketplaceExtensionAction;
use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Data\MarketplaceInstallActorData;
use Capell\Marketplace\Data\MarketplaceUninstallOptionsData;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Enums\MarketplaceInstallSource;
use Capell\Marketplace\Enums\MarketplaceOperationType;
use Capell\Marketplace\Jobs\RunMarketplaceInstallAttemptJob;
use Capell\Marketplace\Jobs\RunMarketplaceUninstallAttemptJob;
use Capell\Marketplace\Jobs\RunMarketplaceUpdateAttemptJob;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Tests\Support\MarketplaceLifecycleQaFixture;
use Capell\Marketplace\Tests\Support\MarketplaceLifecycleQaFixtureServiceProvider;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('runs a fixture package through the queued install update and uninstall lifecycle', function (): void {
    LifecycleRecorderAction::reset();
    Queue::fake();
    // The migration Actions own separate behaviour coverage. Keep this lifecycle
    // fixture from publishing into Testbench's process-shared migration path.
    PublishPendingMigrationsAction::mock()
        ->shouldReceive('handle')
        ->once()
        ->andReturn(new MigrationPublishResult(true, true, 'Fixture migrations published.'));
    RunDatabaseMigrationsAction::mock()
        ->shouldReceive('handle')
        ->once()
        ->andReturn(new MigrationRunResult(0, 'Core migrations ran.'));
    RunPublishedDatabaseMigrationsAction::mock()
        ->shouldReceive('handle')
        ->once()
        ->andReturn(new MigrationRunResult(0, 'Published migrations ran.'));
    RunSettingsMigrationsAction::mock()
        ->shouldReceive('handle')
        ->once()
        ->andReturn(new MigrationRunResult(0, 'Settings migrations ran.'));
    config(['capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api']);

    app()->register(MarketplaceLifecycleQaFixtureServiceProvider::class);
    app()->instance(RunPostOperationHealthCheckAction::class, new RunPostOperationHealthCheckAction);

    $packagePath = sys_get_temp_dir() . '/capell-marketplace-lifecycle-qa-fixture-' . uniqid();
    $initialPath = $packagePath . '/initial';
    $updatedPath = $packagePath . '/updated';

    File::ensureDirectoryExists($initialPath);
    File::ensureDirectoryExists($updatedPath);

    foreach ([$initialPath, $updatedPath] as $path) {
        File::put($path . '/composer.json', json_encode([
            'name' => MarketplaceLifecycleQaFixture::PACKAGE_NAME,
            'autoload' => ['psr-4' => []],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    $initialManifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: MarketplaceLifecycleQaFixture::PACKAGE_NAME,
        surfaces: ['shared'],
        overrides: [
            'kind' => 'plugin',
            'displayName' => 'Marketplace Lifecycle QA Fixture',
            'actions' => [
                'install' => LifecycleRecorderAction::class,
                'uninstall' => LifecycleRecorderAction::class,
            ],
        ],
    ), $initialPath);
    $updatedManifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: MarketplaceLifecycleQaFixture::PACKAGE_NAME,
        surfaces: ['shared'],
        overrides: [
            'kind' => 'plugin',
            'displayName' => 'Marketplace Lifecycle QA Fixture',
            'actions' => [
                'install' => LifecycleRecorderAction::class,
                'uninstall' => LifecycleRecorderAction::class,
            ],
        ],
    ), $updatedPath);

    $listing = ExtensionListingData::fromApiResponse([
        'slug' => 'marketplace-lifecycle-qa-fixture',
        'name' => 'Marketplace Lifecycle QA Fixture',
        'composer_name' => MarketplaceLifecycleQaFixture::PACKAGE_NAME,
        'kind' => 'plugin',
        'price_cents' => 0,
        'is_paid' => false,
        'latest_version' => MarketplaceLifecycleQaFixture::INITIAL_VERSION,
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [[
                'slug' => $listing->slug,
                'name' => $listing->name,
                'composer_name' => $listing->composerName,
                'kind' => $listing->kind,
                'price_cents' => 0,
                'is_paid' => false,
                'latest_version' => MarketplaceLifecycleQaFixture::UPDATED_VERSION,
                'install_eligibility' => [
                    'state' => 'authorized',
                    'can_install' => true,
                    'can_update' => true,
                    'can_run_existing' => true,
                ],
            ]],
            'links' => ['next' => null],
        ]),
    ]);

    $fixture = resolve(MarketplaceLifecycleQaFixture::class);
    $fixture->configurePackage($initialManifest, $updatedManifest);

    try {
        $acquisition = CreateExtensionAcquisitionAction::run($listing);
        $installAttempt = QueueMarketplaceInstallAttemptAction::run(
            listing: $listing,
            acquisition: $acquisition,
            eligibility: ResolveMarketplaceInstallEligibilityAction::run($listing, null),
            betaAcknowledged: false,
            policyEvidence: BuildMarketplaceInstallPolicyEvidenceAction::run($listing),
            actor: MarketplaceInstallActorData::system('marketplace-lifecycle-qa-fixture'),
            source: MarketplaceInstallSource::Cli,
            afterResponse: false,
        );
        new RunMarketplaceInstallAttemptJob((int) $installAttempt->getKey())->handle($fixture);

        $updateAttempt = UpdateMarketplaceExtensionAction::run(
            composerName: MarketplaceLifecycleQaFixture::PACKAGE_NAME,
            actor: MarketplaceInstallActorData::system('marketplace-lifecycle-qa-fixture'),
            source: MarketplaceInstallSource::Cli,
        );
        new RunMarketplaceUpdateAttemptJob((int) $updateAttempt->getKey())->handle($fixture);

        $uninstallAttempt = QueueMarketplaceUninstallAttemptAction::run(
            composerName: MarketplaceLifecycleQaFixture::PACKAGE_NAME,
            extensionSlug: $listing->slug,
            extensionName: $listing->name,
            kind: $listing->kind,
            options: new MarketplaceUninstallOptionsData(
                deletePackage: false,
                deleteData: true,
            ),
            actor: MarketplaceInstallActorData::system('marketplace-lifecycle-qa-fixture'),
            source: MarketplaceInstallSource::Cli,
        );
        new RunMarketplaceUninstallAttemptJob((int) $uninstallAttempt->getKey())->handle($fixture);
    } finally {
        File::deleteDirectory($packagePath);
    }

    $attempts = MarketplaceInstallAttempt::query()->orderBy('id')->get();

    expect($attempts->pluck('operation')->all())->toBe([
        MarketplaceOperationType::Install,
        MarketplaceOperationType::Update,
        MarketplaceOperationType::Uninstall,
    ])->and($attempts->map(static fn (MarketplaceInstallAttempt $attempt): array => [
        'status' => $attempt->status->value,
        'failure_reason' => $attempt->getAttribute('failure_reason'),
    ])->all())->toBe([
        ['status' => MarketplaceInstallIntentStatus::Succeeded->value, 'failure_reason' => null],
        ['status' => MarketplaceInstallIntentStatus::Succeeded->value, 'failure_reason' => null],
        ['status' => MarketplaceInstallIntentStatus::Succeeded->value, 'failure_reason' => null],
    ])
        ->and($fixture->composerCalls())->toBe([
            [
                'composer_name' => MarketplaceLifecycleQaFixture::PACKAGE_NAME,
                'version_constraint' => '^' . MarketplaceLifecycleQaFixture::INITIAL_VERSION,
                'timeout_seconds' => 600,
            ],
            [
                'composer_name' => MarketplaceLifecycleQaFixture::PACKAGE_NAME,
                'version_constraint' => '^' . MarketplaceLifecycleQaFixture::UPDATED_VERSION,
                'timeout_seconds' => 600,
            ],
        ])
        ->and($fixture->prettyVersion(MarketplaceLifecycleQaFixture::PACKAGE_NAME))->toBe(MarketplaceLifecycleQaFixture::UPDATED_VERSION)
        ->and(LifecycleRecorderAction::$calls)->toHaveCount(2)
        ->and(CapellCore::getPackage(MarketplaceLifecycleQaFixture::PACKAGE_NAME)?->isInstalled())->toBeFalse();
});
