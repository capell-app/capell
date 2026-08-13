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
use Capell\Marketplace\Actions\RunPostOperationHealthCheckAction;
use Capell\Marketplace\Contracts\MarketplaceComposerRunner;
use Capell\Marketplace\Data\MarketplaceComposerResultData;
use Capell\Marketplace\Data\MarketplaceHealthCheckResultData;
use Capell\Marketplace\Enums\MarketplaceHealthProbeOutcome;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Enums\MarketplaceOperationType;
use Capell\Marketplace\Jobs\RunMarketplaceInstallAttemptJob;
use Capell\Marketplace\Jobs\RunMarketplaceUninstallAttemptJob;
use Capell\Marketplace\Jobs\RunMarketplaceUpdateAttemptJob;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Tests\Support\MarketplaceLifecycleQaFixture;
use Capell\Marketplace\Tests\Support\MarketplaceLifecycleQaFixtureServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function (): void {
    config([
        'capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api',
        'capell-marketplace.marketplace.cache_ttl_seconds' => 0,
        'capell-marketplace.marketplace.timeout_seconds' => 10,
    ]);
});

/**
 * @return array{package_path: string, fixture: MarketplaceLifecycleQaFixture}
 */
function configureMarketplaceLifecycleCommandFixture(): array
{
    LifecycleRecorderAction::reset();
    Queue::fake();
    // The migration Actions own separate behaviour coverage. Keep this command
    // fixture from publishing into Testbench's process-shared migration path.
    PublishPendingMigrationsAction::mock()
        ->shouldReceive('handle')
        ->andReturn(new MigrationPublishResult(true, true, 'Fixture migrations published.'));
    RunDatabaseMigrationsAction::mock()
        ->shouldReceive('handle')
        ->andReturn(new MigrationRunResult(0, 'Core migrations ran.'));
    RunPublishedDatabaseMigrationsAction::mock()
        ->shouldReceive('handle')
        ->andReturn(new MigrationRunResult(0, 'Published migrations ran.'));
    RunSettingsMigrationsAction::mock()
        ->shouldReceive('handle')
        ->andReturn(new MigrationRunResult(0, 'Settings migrations ran.'));
    app()->register(MarketplaceLifecycleQaFixtureServiceProvider::class);
    app()->instance(RunPostOperationHealthCheckAction::class, new RunPostOperationHealthCheckAction);

    $packagePath = sys_get_temp_dir() . '/capell-marketplace-lifecycle-command-' . uniqid();
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

    $manifestOverrides = [
        'kind' => 'plugin',
        'displayName' => 'Marketplace Lifecycle QA Fixture',
        'actions' => [
            'install' => LifecycleRecorderAction::class,
            'uninstall' => LifecycleRecorderAction::class,
        ],
    ];
    $initialManifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: MarketplaceLifecycleQaFixture::PACKAGE_NAME,
        surfaces: ['shared'],
        overrides: $manifestOverrides,
    ), $initialPath);
    $updatedManifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: MarketplaceLifecycleQaFixture::PACKAGE_NAME,
        surfaces: ['shared'],
        overrides: $manifestOverrides,
    ), $updatedPath);

    Http::fake([
        'https://marketplace.test/api/extensions/by-composer*' => Http::response([
            'data' => [[
                'slug' => 'marketplace-lifecycle-qa-fixture',
                'name' => 'Marketplace Lifecycle QA Fixture',
                'composer_name' => MarketplaceLifecycleQaFixture::PACKAGE_NAME,
                'kind' => 'plugin',
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
        ]),
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [[
                'slug' => 'marketplace-lifecycle-qa-fixture',
                'name' => 'Marketplace Lifecycle QA Fixture',
                'composer_name' => MarketplaceLifecycleQaFixture::PACKAGE_NAME,
                'kind' => 'plugin',
                'price_cents' => 0,
                'is_paid' => false,
                'latest_version' => MarketplaceLifecycleQaFixture::UPDATED_VERSION,
            ]],
            'links' => ['next' => null],
        ]),
    ]);

    $fixture = resolve(MarketplaceLifecycleQaFixture::class);
    $fixture->configurePackage($initialManifest, $updatedManifest);

    return [
        'package_path' => $packagePath,
        'fixture' => $fixture,
    ];
}

it('reports a dry run lifecycle plan as json for a selected extension', function (): void {
    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                [
                    'slug' => 'seo-suite',
                    'name' => 'SEO Suite',
                    'composer_name' => 'capell-app/seo-suite',
                    'kind' => 'tool',
                    'price_cents' => 4900,
                    'is_paid' => true,
                ],
                [
                    'slug' => 'migration-assistant',
                    'name' => 'Migration Assistant',
                    'composer_name' => 'capell-app/migration-assistant',
                    'kind' => 'tool',
                    'price_cents' => 0,
                    'is_paid' => false,
                ],
            ],
            'links' => ['next' => null],
        ]),
    ]);

    $exitCode = Artisan::call('marketplace:qa:extensions-lifecycle', [
        '--dry-run' => true,
        '--json' => true,
        '--only' => 'capell-app/seo-suite',
    ]);

    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($report['ok'])->toBeTrue()
        ->and($report['count'])->toBe(1)
        ->and($report['extensions'][0])->toMatchArray([
            'extension' => 'SEO Suite',
            'composer_package' => 'capell-app/seo-suite',
            'install' => 'dry-run',
            'update' => 'skipped',
            'uninstall' => 'dry-run',
            'delete' => 'dry-run',
            'failure_reason' => null,
        ]);
});

it('marks delete as skipped when skip delete is requested', function (): void {
    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                [
                    'slug' => 'seo-suite',
                    'name' => 'SEO Suite',
                    'composer_name' => 'capell-app/seo-suite',
                    'kind' => 'tool',
                    'price_cents' => 4900,
                    'is_paid' => true,
                ],
            ],
            'links' => ['next' => null],
        ]),
    ]);

    $exitCode = Artisan::call('marketplace:qa:extensions-lifecycle', [
        '--dry-run' => true,
        '--json' => true,
        '--skip-delete' => true,
    ]);

    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($report['extensions'][0]['delete'])->toBe('skipped');
});

it('deduplicates catalogue entries by composer package before running lifecycle qa', function (): void {
    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                [
                    'slug' => 'seo-suite',
                    'name' => 'SEO Suite',
                    'composer_name' => 'capell-app/seo-suite',
                    'kind' => 'tool',
                    'price_cents' => 0,
                    'is_paid' => false,
                ],
                [
                    'slug' => 'seo-suite-duplicate',
                    'name' => 'SEO Suite Duplicate',
                    'composer_name' => 'capell-app/seo-suite',
                    'kind' => 'tool',
                    'price_cents' => 0,
                    'is_paid' => false,
                ],
            ],
            'links' => ['next' => null],
        ]),
    ]);

    $exitCode = Artisan::call('marketplace:qa:extensions-lifecycle', [
        '--dry-run' => true,
        '--json' => true,
        '--only' => 'capell-app/seo-suite',
    ]);

    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($report['ok'])->toBeTrue()
        ->and($report['count'])->toBe(1)
        ->and($report['extensions'][0]['extension'])->toBe('SEO Suite');
});

it('requires a selected extension when an update seed version is supplied', function (): void {
    $exitCode = Artisan::call('marketplace:qa:extensions-lifecycle', [
        '--update-from' => '1.0.0',
    ]);

    expect($exitCode)->toBe(2)
        ->and(Artisan::output())->toContain('requires --only=vendor/package');
});

it('requires one exact semantic version for an update seed', function (): void {
    $exitCode = Artisan::call('marketplace:qa:extensions-lifecycle', [
        '--only' => 'capell-app/seo-suite',
        '--update-from' => '^1.0',
    ]);

    expect($exitCode)->toBe(2)
        ->and(Artisan::output())->toContain('must be one exact semantic version');
});

it('fails truthfully when the selected extension is absent from the catalogue', function (): void {
    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [],
            'links' => ['next' => null],
        ]),
    ]);

    $exitCode = Artisan::call('marketplace:qa:extensions-lifecycle', [
        '--json' => true,
        '--only' => 'capell-app/missing-extension',
    ]);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($report)->toMatchArray([
            'ok' => false,
            'count' => 0,
            'extensions' => [],
            'error' => 'Marketplace did not return the requested extension [capell-app/missing-extension].',
        ]);
});

it('refuses to override protected extension acquisition with an update seed version', function (): void {
    Queue::fake();
    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [[
                'slug' => 'protected-tool',
                'name' => 'Protected Tool',
                'composer_name' => 'capell-app/protected-tool',
                'kind' => 'tool',
                'price_cents' => 4900,
                'is_paid' => true,
                'latest_version' => '1.1.0',
            ]],
            'links' => ['next' => null],
        ]),
    ]);

    $exitCode = Artisan::call('marketplace:qa:extensions-lifecycle', [
        '--json' => true,
        '--only' => 'capell-app/protected-tool',
        '--update-from' => '1.0.0',
    ]);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($report['extensions'][0])->toMatchArray([
            'install' => 'failed',
            'update' => 'skipped',
            'uninstall' => 'skipped',
            'delete' => 'skipped',
            'failure_reason' => 'The --update-from lifecycle proof is limited to free extensions without protected Composer credentials or signed activation data.',
        ])
        ->and(MarketplaceInstallAttempt::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});

it('rejects prompt-free beta lifecycle installs without explicit acknowledgement', function (): void {
    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [[
                'slug' => 'beta-tool',
                'name' => 'Beta Tool',
                'composer_name' => 'capell-app/beta-tool',
                'kind' => 'tool',
                'price_cents' => 0,
                'is_paid' => false,
                'latest_version' => '1.0.0',
                'catalogue_role' => 'extension',
                'maturity' => 'beta',
                'maturity_label' => 'Beta',
                'included_with_capell_all' => false,
            ]],
            'links' => ['next' => null],
        ]),
    ]);

    $exitCode = Artisan::call('marketplace:qa:extensions-lifecycle', [
        '--json' => true,
        '--only' => 'capell-app/beta-tool',
    ]);

    $attempt = MarketplaceInstallAttempt::query()->sole();
    $evidence = $attempt->policy_evidence;
    expect($evidence)->toBeArray();
    assert(is_array($evidence));

    expect($exitCode)->toBe(1)
        ->and($attempt->status)->toBe(MarketplaceInstallIntentStatus::Blocked)
        ->and($attempt->failure_reason)->toBe('beta_acknowledgement_required')
        ->and($attempt->beta_acknowledged)->toBeFalse()
        ->and($evidence['consentAllowed'])->toBeFalse();
});

it('runs lifecycle qa through the install attempt pipeline and reports composer failures', function (): void {
    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                [
                    'slug' => 'broken-free-tool',
                    'name' => 'Broken Free Tool',
                    'composer_name' => 'capell-app/broken-free-tool',
                    'kind' => 'tool',
                    'price_cents' => 0,
                    'is_paid' => false,
                    'latest_version' => '1.2.0',
                ],
                [
                    'slug' => 'second-free-tool',
                    'name' => 'Second Free Tool',
                    'composer_name' => 'capell-app/second-free-tool',
                    'kind' => 'tool',
                    'price_cents' => 0,
                    'is_paid' => false,
                    'latest_version' => '1.0.0',
                ],
            ],
            'links' => ['next' => null],
        ]),
    ]);

    $composer = new class implements MarketplaceComposerRunner
    {
        /** @var list<array{composer_name: string, version_constraint: string, timeout_seconds: int}> */
        public array $calls = [];

        public function require(string $composerName, string $versionConstraint, int $timeoutSeconds): MarketplaceComposerResultData
        {
            $this->calls[] = [
                'composer_name' => $composerName,
                'version_constraint' => $versionConstraint,
                'timeout_seconds' => $timeoutSeconds,
            ];

            return new MarketplaceComposerResultData(
                exitCode: 1,
                output: 'Composer output from QA run.',
                errorOutput: 'Composer failed for QA.',
            );
        }
    };

    app()->instance(MarketplaceComposerRunner::class, $composer);

    $exitCode = Artisan::call('marketplace:qa:extensions-lifecycle', [
        '--json' => true,
        '--stop-on-failure' => true,
    ]);

    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $attempt = MarketplaceInstallAttempt::query()
        ->where('composer_name', 'capell-app/broken-free-tool')
        ->sole();

    expect($exitCode)->toBe(1)
        ->and($report['ok'])->toBeFalse()
        ->and($report['count'])->toBe(1)
        ->and($report['extensions'][0])->toMatchArray([
            'extension' => 'Broken Free Tool',
            'composer_package' => 'capell-app/broken-free-tool',
            'install' => 'failed',
            'uninstall' => 'skipped',
            'delete' => 'skipped',
            'failure_reason' => 'Composer failed for QA.',
        ])
        ->and($composer->calls)->toHaveCount(1)
        ->and($composer->calls[0])->toMatchArray([
            'composer_name' => 'capell-app/broken-free-tool',
            'version_constraint' => '^1.2.0',
        ])
        ->and($attempt->status)->toBe(MarketplaceInstallIntentStatus::Failed)
        ->and($attempt->failure_reason)->toBe('Composer failed for QA.')
        ->and($attempt->failure_stage)->toBe(MarketplaceInstallFailureStage::Composer->value)
        ->and($attempt->context)->toMatchArray([
            'source' => 'marketplace_lifecycle_qa',
        ]);

    expect(MarketplaceInstallAttempt::query()
        ->where('composer_name', 'capell-app/second-free-tool')
        ->exists())->toBeFalse();
});

it('runs a complete install uninstall and data deletion lifecycle through the qa command', function (): void {
    LifecycleRecorderAction::reset();
    $packageName = 'capell-app/marketplace-lifecycle-qa-fixture';
    $packagePath = sys_get_temp_dir() . '/capell-marketplace-lifecycle-qa-' . uniqid();

    File::ensureDirectoryExists($packagePath);
    File::put($packagePath . '/composer.json', json_encode([
        'name' => $packageName,
        'autoload' => ['psr-4' => []],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    $manifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: $packageName,
        surfaces: ['shared'],
        overrides: [
            'kind' => 'plugin',
            'displayName' => 'Marketplace Lifecycle QA Fixture',
            'actions' => [
                'install' => LifecycleRecorderAction::class,
                'uninstall' => LifecycleRecorderAction::class,
            ],
        ],
    ), $packagePath);
    File::put($packagePath . '/capell.json', json_encode(
        $manifest->toArray(),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ));

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [[
                'slug' => 'marketplace-lifecycle-qa-fixture',
                'name' => 'Marketplace Lifecycle QA Fixture',
                'composer_name' => $packageName,
                'kind' => 'plugin',
                'price_cents' => 0,
                'is_paid' => false,
                'latest_version' => '1.0.0',
            ]],
            'links' => ['next' => null],
        ]),
    ]);

    app()->instance(MarketplaceComposerRunner::class, new readonly class($manifest) implements MarketplaceComposerRunner
    {
        public function __construct(private CapellManifestData $manifest) {}

        public function require(string $composerName, string $versionConstraint, int $timeoutSeconds): MarketplaceComposerResultData
        {
            CapellCore::registerManifestPackage($this->manifest);

            return new MarketplaceComposerResultData(0, 'Package installed.', '');
        }
    });

    app()->instance(RunPostOperationHealthCheckAction::class, new class
    {
        public function handle(int $budgetSeconds): MarketplaceHealthCheckResultData
        {
            unset($budgetSeconds);

            return new MarketplaceHealthCheckResultData(
                MarketplaceHealthProbeOutcome::Passed,
                MarketplaceHealthProbeOutcome::Passed,
            );
        }
    });

    try {
        $exitCode = Artisan::call('marketplace:qa:extensions-lifecycle', [
            '--json' => true,
            '--only' => $packageName,
        ]);
    } finally {
        File::deleteDirectory($packagePath);
    }

    $attempts = MarketplaceInstallAttempt::query()->orderBy('id')->get();

    expect($exitCode)->toBe(0)
        ->and($attempts)->toHaveCount(2)
        ->and($attempts->pluck('operation')->all())->toBe([
            MarketplaceOperationType::Install,
            MarketplaceOperationType::Uninstall,
        ])
        ->and($attempts->every(fn (MarketplaceInstallAttempt $attempt): bool => $attempt->status === MarketplaceInstallIntentStatus::Succeeded))->toBeTrue()
        ->and(LifecycleRecorderAction::$calls)->toHaveCount(2)
        ->and(CapellCore::getPackage($packageName)?->isInstalled())->toBeFalse();
});

it('runs a selected extension through queued install update and uninstall operations', function (): void {
    ['package_path' => $packagePath, 'fixture' => $fixture] = configureMarketplaceLifecycleCommandFixture();
    $output = new BufferedOutput;

    try {
        $exitCode = Artisan::call('marketplace:qa:extensions-lifecycle', [
            '--json' => true,
            '--only' => MarketplaceLifecycleQaFixture::PACKAGE_NAME,
            '--update-from' => MarketplaceLifecycleQaFixture::INITIAL_VERSION,
        ], $output);
    } finally {
        File::deleteDirectory($packagePath);
    }

    $report = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);
    $attempts = MarketplaceInstallAttempt::query()->orderBy('id')->get();

    expect($exitCode)->toBe(0)
        ->and($report['extensions'][0])->toMatchArray([
            'install' => 'passed',
            'update' => 'passed',
            'uninstall' => 'passed',
            'delete' => 'passed',
            'failure_reason' => null,
        ])
        ->and($attempts->pluck('operation')->all())->toBe([
            MarketplaceOperationType::Install,
            MarketplaceOperationType::Update,
            MarketplaceOperationType::Uninstall,
        ])
        ->and($attempts->every(
            fn (MarketplaceInstallAttempt $attempt): bool => $attempt->status === MarketplaceInstallIntentStatus::Succeeded,
        ))->toBeTrue()
        ->and($fixture->composerCalls())->toBe([
            [
                'composer_name' => MarketplaceLifecycleQaFixture::PACKAGE_NAME,
                'version_constraint' => MarketplaceLifecycleQaFixture::INITIAL_VERSION,
                'timeout_seconds' => 600,
            ],
            [
                'composer_name' => MarketplaceLifecycleQaFixture::PACKAGE_NAME,
                'version_constraint' => '^' . MarketplaceLifecycleQaFixture::UPDATED_VERSION,
                'timeout_seconds' => 600,
            ],
        ])
        ->and(LifecycleRecorderAction::$calls)->toHaveCount(2)
        ->and(CapellCore::getPackage(MarketplaceLifecycleQaFixture::PACKAGE_NAME)?->isInstalled())->toBeFalse();

    Queue::assertNotPushed(RunMarketplaceInstallAttemptJob::class);
    Queue::assertNotPushed(RunMarketplaceUpdateAttemptJob::class);
    Queue::assertNotPushed(RunMarketplaceUninstallAttemptJob::class);
});

it('fails an inline attempt terminally when the composer operation lock is busy', function (): void {
    ['package_path' => $packagePath] = configureMarketplaceLifecycleCommandFixture();
    $lock = Cache::lock('capell-marketplace:composer-install', RunMarketplaceInstallAttemptJob::jobTimeoutSeconds());
    $output = new BufferedOutput;

    expect($lock->get())->toBeTrue();

    try {
        $exitCode = Artisan::call('marketplace:qa:extensions-lifecycle', [
            '--json' => true,
            '--only' => MarketplaceLifecycleQaFixture::PACKAGE_NAME,
        ], $output);
    } finally {
        $lock->release();
        File::deleteDirectory($packagePath);
    }

    $report = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);
    $attempt = MarketplaceInstallAttempt::query()->sole();
    $fixture = resolve(MarketplaceLifecycleQaFixture::class);

    expect($exitCode)->toBe(1)
        ->and($report['extensions'][0])->toMatchArray([
            'install' => 'failed',
            'update' => 'skipped',
            'uninstall' => 'skipped',
            'delete' => 'skipped',
            'failure_reason' => 'The Marketplace lifecycle QA operation could not complete synchronously. Another Composer operation may be active.',
        ])
        ->and($attempt->status)->toBe(MarketplaceInstallIntentStatus::Failed)
        ->and($attempt->failure_stage)->toBe(MarketplaceInstallFailureStage::Queue->value)
        ->and($fixture->composerCalls())->toBe([]);

    Queue::assertNotPushed(RunMarketplaceInstallAttemptJob::class);
});

it('stops after a failed queued update and reports uninstall as skipped', function (): void {
    ['package_path' => $packagePath, 'fixture' => $fixture] = configureMarketplaceLifecycleCommandFixture();
    $fixture->failWhenVersionConstraint('^' . MarketplaceLifecycleQaFixture::UPDATED_VERSION);
    $output = new BufferedOutput;

    try {
        $exitCode = Artisan::call('marketplace:qa:extensions-lifecycle', [
            '--json' => true,
            '--only' => MarketplaceLifecycleQaFixture::PACKAGE_NAME,
            '--update-from' => MarketplaceLifecycleQaFixture::INITIAL_VERSION,
        ], $output);
    } finally {
        File::deleteDirectory($packagePath);
    }

    $report = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);
    $attempts = MarketplaceInstallAttempt::query()->orderBy('id')->get();

    expect($exitCode)->toBe(1)
        ->and($report['extensions'][0])->toMatchArray([
            'install' => 'passed',
            'update' => 'failed',
            'uninstall' => 'skipped',
            'delete' => 'skipped',
            'failure_reason' => 'The deterministic Composer fixture was instructed to fail.',
        ])
        ->and($attempts->pluck('operation')->all())->toBe([
            MarketplaceOperationType::Install,
            MarketplaceOperationType::Update,
        ])
        ->and($attempts->last()?->status)->toBe(MarketplaceInstallIntentStatus::Failed)
        ->and(CapellCore::getPackage(MarketplaceLifecycleQaFixture::PACKAGE_NAME)?->isInstalled())->toBeTrue();

    Queue::assertNotPushed(RunMarketplaceInstallAttemptJob::class);
    Queue::assertNotPushed(RunMarketplaceUpdateAttemptJob::class);
    Queue::assertNotPushed(RunMarketplaceUninstallAttemptJob::class);
});
