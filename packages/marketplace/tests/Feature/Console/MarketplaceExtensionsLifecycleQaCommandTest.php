<?php

declare(strict_types=1);

use Capell\Marketplace\Contracts\MarketplaceComposerRunner;
use Capell\Marketplace\Data\MarketplaceComposerResultData;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Illuminate\Support\Facades\Artisan;
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
