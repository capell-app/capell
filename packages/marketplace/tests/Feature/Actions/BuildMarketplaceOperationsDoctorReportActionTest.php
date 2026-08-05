<?php

declare(strict_types=1);

use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Capell\Marketplace\Actions\BuildMarketplaceOperationsDoctorReportAction;
use Capell\Marketplace\Actions\EvaluateMarketplaceEnvironmentReadinessAction;
use Capell\Marketplace\Enums\MarketplaceInstallCapability;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;

it('reports stuck and unresolved failed operations without exposing package diagnostics', function (): void {
    config()->set('queue.connections.database.retry_after', 900);

    MarketplaceInstallAttempt::query()->create([
        'composer_name' => 'capell-app/stuck-secret-package',
        'extension_slug' => 'stuck-secret-package',
        'extension_name' => 'Stuck Secret Package',
        'kind' => 'tool',
        'status' => MarketplaceInstallIntentStatus::Running,
        'started_at' => now()->subMinutes(30),
        'heartbeat_at' => now()->subMinutes(30),
    ]);

    MarketplaceInstallAttempt::query()->create([
        'composer_name' => 'capell-app/failed-secret-package',
        'extension_slug' => 'failed-secret-package',
        'extension_name' => 'Failed Secret Package',
        'kind' => 'tool',
        'status' => MarketplaceInstallIntentStatus::Failed,
        'failure_reason' => 'private token value',
        'failure_type' => 'unknown',
        'failure_stage' => 'composer',
        'completed_at' => now(),
    ]);

    $report = BuildMarketplaceOperationsDoctorReportAction::run(staleAfterMinutes: 15);
    $payload = json_encode($report->toArray(), JSON_THROW_ON_ERROR);

    expect($report->status)->toBe('failed')
        ->and($report->checks->firstWhere('id', 'marketplace.operations.schema')?->passed)->toBeTrue()
        ->and($report->checks->firstWhere('id', 'marketplace.operations.stuck')?->passed)->toBeFalse()
        ->and($report->checks->firstWhere('id', 'marketplace.operations.failed')?->passed)->toBeFalse()
        ->and($payload)->not->toContain('stuck-secret-package')
        ->and($payload)->not->toContain('failed-secret-package')
        ->and($payload)->not->toContain('private token value');
});

it('lets only a critical failure decide the report status', function (): void {
    config()->set('queue.connections.database.retry_after', 900);

    MarketplaceInstallAttempt::query()->create([
        'composer_name' => 'capell-app/failed-package',
        'extension_slug' => 'failed-package',
        'extension_name' => 'Failed Package',
        'kind' => 'tool',
        'status' => MarketplaceInstallIntentStatus::Failed,
        'failure_type' => 'unknown',
        'failure_stage' => 'composer',
        'completed_at' => now(),
    ]);

    $report = BuildMarketplaceOperationsDoctorReportAction::run(staleAfterMinutes: 15);
    $failedCheck = $report->checks->firstWhere('id', 'marketplace.operations.failed');

    // Unresolved failed operations need an operator, so the check declares
    // itself critical instead of relying on an aggregate that ignores severity.
    expect($failedCheck?->severity)->toBe(DoctorCheckSeverity::Critical)
        ->and($failedCheck?->isCriticalFailure())->toBeTrue()
        ->and($failedCheck?->remediation)->not->toBeNull()
        ->and($report->status)->toBe('failed');
});

it('fails when queue retry_after can make a long operation run twice', function (): void {
    config()->set('queue.connections.database.retry_after', 90);

    $check = BuildMarketplaceOperationsDoctorReportAction::run()
        ->checks
        ->firstWhere('id', 'marketplace.operations.queue-retry-after');

    expect($check?->passed)->toBeFalse()
        ->and($check?->evidence)->toMatchArray([
            'retry_after_seconds' => 90,
            'job_timeout_seconds' => 720,
        ]);
});

it('reports the host install capability without failing a manual-only host', function (): void {
    fakeMarketplaceEnvironmentReadiness(capability: MarketplaceInstallCapability::ManualOnly);

    $check = BuildMarketplaceOperationsDoctorReportAction::run()
        ->checks
        ->firstWhere('id', 'marketplace.operations.environment-readiness');

    expect($check?->passed)->toBeTrue()
        ->and($check?->evidence['capability'])->toBe(MarketplaceInstallCapability::ManualOnly->value)
        ->and($check?->evidence['docs_path'])->toBe(EvaluateMarketplaceEnvironmentReadinessAction::DOCS_PATH);
});

it('fails the report when the host is blocked', function (): void {
    fakeMarketplaceEnvironmentReadiness(capability: MarketplaceInstallCapability::Blocked);

    $report = BuildMarketplaceOperationsDoctorReportAction::run();

    expect($report->status)->toBe('failed')
        ->and($report->checks->firstWhere('id', 'marketplace.operations.environment-readiness')?->passed)->toBeFalse();
});
