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
        ->toBe(RunMarketplaceInstallAttemptJob::jobTimeoutSeconds());
});

it('keeps the composer timeout below the job timeout at every configured value', function (): void {
    // The job has to survive Composer and still finalise the attempt afterwards.
    // Both numbers are operator-configurable now, so the invariant needs pinning
    // rather than assuming the defaults stay in their original relationship.
    foreach ([null, 1, 600, 7200] as $configuredComposerTimeout) {
        config()->set('capell.process.composer.timeout_seconds', $configuredComposerTimeout);

        $chain = MarketplaceQueueTimeoutChain::resolve();

        expect($chain->composerTimeoutSeconds)->toBeLessThan($chain->jobTimeoutSeconds);
    }
});

it('calls a retry window at or below the job timeout unsafe, and one above it safe', function (): void {
    config()->set('queue.connections.database.retry_after', RunMarketplaceInstallAttemptJob::jobTimeoutSeconds());
    expect(MarketplaceQueueTimeoutChain::resolve()->isSafe())->toBeFalse();

    config()->set('queue.connections.database.retry_after', RunMarketplaceInstallAttemptJob::jobTimeoutSeconds() + 1);
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
        ->toBe(RunMarketplaceInstallAttemptJob::jobTimeoutSeconds());
});

it('derives the job timeout from the configured composer timeout', function (): void {
    config()->set('capell.process.composer.timeout_seconds', 900);
    config()->set('capell.process.composer.job_timeout_buffer_seconds', 120);

    $chain = MarketplaceQueueTimeoutChain::resolve();

    expect($chain->composerTimeoutSeconds)->toBe(900)
        ->and($chain->jobTimeoutSeconds)->toBe(1020)
        ->and(RunMarketplaceInstallAttemptJob::jobTimeoutSeconds())->toBe(1020);
});

it('keeps readiness in step with a raised composer timeout', function (): void {
    // A retry window that was safe against the default 720s job timeout is not
    // safe once the operator gives Composer fifteen minutes.
    config()->set('queue.connections.database.retry_after', 800);
    config()->set('capell.process.composer.timeout_seconds', 600);

    expect(MarketplaceQueueTimeoutChain::resolve()->isSafe())->toBeTrue();

    config()->set('capell.process.composer.timeout_seconds', 900);

    expect(MarketplaceQueueTimeoutChain::resolve()->isSafe())->toBeFalse();
});

it('ignores a nonsensical configured composer timeout', function (): void {
    config()->set('capell.process.composer.timeout_seconds', 0);

    expect(RunMarketplaceInstallAttemptJob::composerTimeoutSeconds())
        ->toBe(RunMarketplaceInstallAttemptJob::DEFAULT_COMPOSER_TIMEOUT_SECONDS);
});
