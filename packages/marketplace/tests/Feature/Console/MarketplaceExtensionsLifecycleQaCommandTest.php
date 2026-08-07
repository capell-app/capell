<?php

declare(strict_types=1);

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
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config([
        'capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api',
        'capell-marketplace.marketplace.cache_ttl_seconds' => 0,
        'capell-marketplace.marketplace.timeout_seconds' => 10,
    ]);
});

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
