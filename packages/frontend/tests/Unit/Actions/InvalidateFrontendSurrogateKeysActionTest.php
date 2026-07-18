<?php

declare(strict_types=1);

use Capell\Frontend\Actions\InvalidateFrontendSurrogateKeysAction;
use Capell\Frontend\Jobs\PurgeCdnCacheJob;
use Capell\Frontend\Support\Cache\FragmentCache;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

it('invalidates local fragments and queues configured CDN purges', function (): void {
    config([
        'capell-frontend.cdn_provider' => 'fastly',
        'capell-frontend.purge_queue' => 'cdn-purges',
    ]);
    Bus::fake([PurgeCdnCacheJob::class]);

    resolve(FragmentCache::class)->remember(
        'shared-fragment',
        static fn (): string => 'cached fragment',
        surrogateKeys: ['site-1'],
    );

    InvalidateFrontendSurrogateKeysAction::run(['site-1']);

    expect(Cache::has('fragment:shared-fragment'))->toBeFalse();

    Bus::assertDispatched(
        PurgeCdnCacheJob::class,
        fn (PurgeCdnCacheJob $job): bool => $job->queue === 'cdn-purges',
    );
});

it('invalidates local fragments without queueing when no CDN is configured', function (): void {
    config(['capell-frontend.cdn_provider' => null]);
    Bus::fake([PurgeCdnCacheJob::class]);

    resolve(FragmentCache::class)->remember(
        'local-fragment',
        static fn (): string => 'cached fragment',
        surrogateKeys: ['page-1'],
    );

    InvalidateFrontendSurrogateKeysAction::run(['page-1']);

    expect(Cache::has('fragment:local-fragment'))->toBeFalse();
    Bus::assertNotDispatched(PurgeCdnCacheJob::class);
});
