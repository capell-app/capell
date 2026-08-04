<?php

declare(strict_types=1);

use Capell\Marketplace\Actions\BuildMarketplaceOperationsDoctorReportAction;
use Capell\Marketplace\Actions\EvaluateMarketplaceEnvironmentReadinessAction;
use Capell\Marketplace\Enums\MarketplaceReadinessStatus;
use Capell\Marketplace\Jobs\RunMarketplaceInstallAttemptJob;
use Capell\Marketplace\Support\MarketplaceQueueTimeoutChain;

beforeEach(function (): void {
    EvaluateMarketplaceEnvironmentReadinessAction::forget();
    config()->set('capell-marketplace.marketplace.operations_queue_connection', 'database');
});

afterEach(function (): void {
    EvaluateMarketplaceEnvironmentReadinessAction::forget();
});

it('takes the job timeout from the job itself rather than a copied literal', function (): void {
    expect(MarketplaceQueueTimeoutChain::resolve()->jobTimeoutSeconds)
        ->toBe(RunMarketplaceInstallAttemptJob::JOB_TIMEOUT_SECONDS);
});

it('calls a retry window at or below the job timeout unsafe, and one above it safe', function (): void {
    config()->set('queue.connections.database.retry_after', RunMarketplaceInstallAttemptJob::JOB_TIMEOUT_SECONDS);
    expect(MarketplaceQueueTimeoutChain::resolve()->isSafe())->toBeFalse();

    config()->set('queue.connections.database.retry_after', RunMarketplaceInstallAttemptJob::JOB_TIMEOUT_SECONDS + 1);
    expect(MarketplaceQueueTimeoutChain::resolve()->isSafe())->toBeTrue();
});

it('treats a connection with no numeric retry_after as safe', function (): void {
    config()->set('queue.connections.database.retry_after', null);

    expect(MarketplaceQueueTimeoutChain::resolve()->retryAfterSeconds)->toBeNull()
        ->and(MarketplaceQueueTimeoutChain::resolve()->isSafe())->toBeTrue();
});

it('gives readiness and the operations doctor the same verdict for one host', function (): void {
    config()->set('queue.connections.database.retry_after', 90);
    config()->set('capell.release_root_mode', 'mutable');
    config()->set('capell.server_side_tooling', true);

    $timeoutChain = EvaluateMarketplaceEnvironmentReadinessAction::run()->check('timeout_chain');
    $doctorCheck = BuildMarketplaceOperationsDoctorReportAction::run()
        ->checks
        ->firstWhere('id', 'marketplace.operations.queue-retry-after');

    expect($timeoutChain?->status)->toBe(MarketplaceReadinessStatus::Fail)
        ->and($doctorCheck?->passed)->toBeFalse()
        ->and($doctorCheck?->evidence['job_timeout_seconds'])
        ->toBe(RunMarketplaceInstallAttemptJob::JOB_TIMEOUT_SECONDS);
});
