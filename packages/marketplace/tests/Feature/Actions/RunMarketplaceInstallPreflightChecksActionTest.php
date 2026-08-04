<?php

declare(strict_types=1);

use Capell\Marketplace\Actions\RunMarketplaceInstallPreflightChecksAction;
use Capell\Marketplace\Enums\MarketplaceInstallCapability;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Enums\MarketplaceReadinessStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;

it('prepends the host readiness checks without changing the result shape', function (): void {
    fakeMarketplaceEnvironmentReadiness();

    $result = RunMarketplaceInstallPreflightChecksAction::run(preflightAttempt());

    expect($result)->toHaveKeys(['passed', 'checks'])
        ->and($result['passed'])->toBeTrue()
        ->and($result['checks'][0]['name'])->toBe('environment_process_execution');

    foreach ($result['checks'] as $check) {
        expect($check)->toHaveKeys(['name', 'passed', 'message', 'remediation', 'docs_anchor'])
            ->and($check['name'])->toBeString()
            ->and($check['passed'])->toBeBool()
            ->and($check['message'])->toBeString();
    }
});

it('fails preflight when the host cannot run an automated install', function (): void {
    fakeMarketplaceEnvironmentReadiness(
        capability: MarketplaceInstallCapability::ManualOnly,
        processExecutionStatus: MarketplaceReadinessStatus::Fail,
    );

    $result = RunMarketplaceInstallPreflightChecksAction::run(preflightAttempt());

    $processExecution = collect($result['checks'])->firstWhere('name', 'environment_process_execution');

    expect($result['passed'])->toBeFalse()
        ->and($processExecution['passed'])->toBeFalse()
        ->and($processExecution['remediation'])->not->toBeNull()
        ->and($processExecution['docs_anchor'])->toBe('process-execution');
});

it('resolves a translation for every preflight message rather than emitting a raw key', function (): void {
    fakeMarketplaceEnvironmentReadiness();

    $result = RunMarketplaceInstallPreflightChecksAction::run(preflightAttempt());

    expect($result['checks'])->not->toBe([]);

    foreach ($result['checks'] as $check) {
        expect($check['message'])->not->toBe('')
            ->and($check['message'])->not->toContain('capell-marketplace::')
            ->and($check['remediation'] ?? '')->not->toContain('capell-marketplace::');
    }

    // Spot-check that a per-attempt message really comes from the lang file
    // rather than merely looking like prose.
    $queueReady = collect($result['checks'])->firstWhere('name', 'queue_ready');

    expect($queueReady['message'])->toBe(
        (string) __('capell-marketplace::marketplace.readiness.preflight.queue_ready_pass'),
    );
});

it('reports the queue retry_after rule once, as a readiness check', function (): void {
    fakeMarketplaceEnvironmentReadiness();

    $names = array_column(RunMarketplaceInstallPreflightChecksAction::run(preflightAttempt())['checks'], 'name');

    // One condition, one failure: the rule lives in readiness and the
    // per-attempt preflight must not restate it under a second name.
    expect($names)->not->toContain('queue_retry_after');
});

it('carries remediation and a docs anchor on every per-attempt failure', function (): void {
    fakeMarketplaceEnvironmentReadiness();

    $running = preflightAttempt(['extension_slug' => 'preflight-duplicate-source']);
    $running->update(['status' => MarketplaceInstallIntentStatus::Running]);

    $result = RunMarketplaceInstallPreflightChecksAction::run(
        preflightAttempt(['extension_slug' => 'preflight-duplicate-retry']),
    );

    $duplicate = collect($result['checks'])->firstWhere('name', 'no_duplicate_active_install');

    expect($result['passed'])->toBeFalse()
        ->and($duplicate['passed'])->toBeFalse()
        ->and($duplicate['remediation'])->not->toBeNull()
        ->and($duplicate['docs_anchor'])->toBe('no-duplicate-active-install');
});

/** @param array<string, mixed> $attributes */
function preflightAttempt(array $attributes = []): MarketplaceInstallAttempt
{
    return MarketplaceInstallAttempt::query()->create([
        'composer_name' => 'capell-app/preflight-suite',
        'extension_slug' => 'preflight-suite',
        'extension_name' => 'Preflight Suite',
        'kind' => 'tool',
        'status' => MarketplaceInstallIntentStatus::Queued,
        'version_constraint' => '^1.0',
        'queued_at' => now(),
        ...$attributes,
    ]);
}
