<?php

declare(strict_types=1);

use Capell\Marketplace\Jobs\WarmMarketplaceCatalogueCacheJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;

it('coalesces equivalent catalogue warm queries with bounded retries', function (): void {
    $job = new WarmMarketplaceCatalogueCacheJob([
        'search' => 'forms',
        'capabilities' => ['payments', 'forms', 'payments'],
    ]);
    $equivalentJob = new WarmMarketplaceCatalogueCacheJob([
        'capabilities' => ['forms', 'payments'],
        'search' => 'forms',
    ]);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe($equivalentJob->uniqueId())
        ->and($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([30, 120]);
});
