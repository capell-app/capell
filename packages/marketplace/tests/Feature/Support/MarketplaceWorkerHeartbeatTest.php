<?php

declare(strict_types=1);

use Capell\Marketplace\Jobs\RecordMarketplaceWorkerHeartbeatJob;
use Capell\Marketplace\Support\MarketplaceQueueWorkerCommand;
use Capell\Marketplace\Support\MarketplaceWorkerHeartbeat;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    MarketplaceWorkerHeartbeat::forget();
});

it('records when a worker was last seen', function (): void {
    expect(MarketplaceWorkerHeartbeat::seenAt())->toBeNull()
        ->and(MarketplaceWorkerHeartbeat::isFresh())->toBeFalse();

    MarketplaceWorkerHeartbeat::record();

    expect(MarketplaceWorkerHeartbeat::seenAt())->not->toBeNull()
        ->and(MarketplaceWorkerHeartbeat::isFresh())->toBeTrue()
        ->and(Cache::get(MarketplaceWorkerHeartbeat::CACHE_KEY))->not->toBeNull();
});

it('treats a heartbeat older than the configured window as stale', function (): void {
    config()->set('capell-marketplace.marketplace.worker_heartbeat_stale_after_seconds', 120);

    Cache::put(MarketplaceWorkerHeartbeat::CACHE_KEY, now()->subSeconds(300)->toIso8601String());

    expect(MarketplaceWorkerHeartbeat::staleAfterSeconds())->toBe(120)
        ->and(MarketplaceWorkerHeartbeat::isFresh())->toBeFalse();
});

it('refuses to read a heartbeat from the future as recently seen', function (): void {
    config()->set('capell-marketplace.marketplace.worker_heartbeat_stale_after_seconds', 120);

    // A node whose clock runs ahead, or one corrected backwards after writing.
    // An absolute comparison would call this fresh for the length of the skew,
    // which is precisely when "a worker ran recently" is least trustworthy.
    Cache::put(MarketplaceWorkerHeartbeat::CACHE_KEY, now()->addSeconds(600)->toIso8601String());

    expect(MarketplaceWorkerHeartbeat::isFresh())->toBeFalse();
});

it('records the heartbeat when the probe job runs on a worker', function (): void {
    new RecordMarketplaceWorkerHeartbeatJob()->handle();

    expect(MarketplaceWorkerHeartbeat::isFresh())->toBeTrue();
});

it('dispatches the probe job on the marketplace queue connection', function (): void {
    config()->set('capell-marketplace.marketplace.operations_queue_connection', 'redis');
    config()->set('capell-marketplace.marketplace.operations_queue', 'capell-operations');

    $job = new RecordMarketplaceWorkerHeartbeatJob;

    expect($job->connection)->toBe('redis')
        ->and($job->queue)->toBe('capell-operations');
});

it('prints the queue worker command for this installation', function (): void {
    config()->set('capell-marketplace.marketplace.operations_queue_connection', 'redis');
    config()->set('capell-marketplace.marketplace.operations_queue', 'capell-operations');

    expect(MarketplaceQueueWorkerCommand::forInstallation())
        ->toBe('php artisan queue:work redis --queue=capell-operations');
});

it('knows when the marketplace queue runs synchronously', function (): void {
    config()->set('capell-marketplace.marketplace.operations_queue_connection', 'sync');
    config()->set('queue.connections.sync.driver', 'sync');

    expect(MarketplaceQueueWorkerCommand::isSynchronous())->toBeTrue();

    config()->set('capell-marketplace.marketplace.operations_queue_connection', 'database');

    expect(MarketplaceQueueWorkerCommand::isSynchronous())->toBeFalse();
});
