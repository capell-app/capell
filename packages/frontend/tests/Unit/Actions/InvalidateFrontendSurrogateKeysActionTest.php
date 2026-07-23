<?php

declare(strict_types=1);

use Capell\Frontend\Actions\InvalidateFrontendSurrogateKeysAction;
use Capell\Frontend\Jobs\FlushCdnPurgeBatchJob;
use Capell\Frontend\Support\Cache\CdnPurgeBuffer;
use Capell\Frontend\Support\Cache\FragmentCache;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

it('invalidates local fragments and queues configured CDN purges', function (): void {
    config([
        'capell-frontend.cdn_provider' => 'fastly',
        'capell-frontend.purge_queue' => 'cdn-purges',
    ]);
    Bus::fake([FlushCdnPurgeBatchJob::class]);

    resolve(FragmentCache::class)->remember(
        'shared-fragment',
        static fn (): string => 'cached fragment',
        surrogateKeys: ['site-1'],
    );

    InvalidateFrontendSurrogateKeysAction::run(['site-1']);

    expect(Cache::has('fragment:shared-fragment'))->toBeFalse();

    Bus::assertDispatched(
        FlushCdnPurgeBatchJob::class,
        fn (FlushCdnPurgeBatchJob $job): bool => $job->queue === 'cdn-purges',
    );
});

it('invalidates local fragments without queueing when no CDN is configured', function (): void {
    config(['capell-frontend.cdn_provider' => null]);
    Bus::fake([FlushCdnPurgeBatchJob::class]);

    resolve(FragmentCache::class)->remember(
        'local-fragment',
        static fn (): string => 'cached fragment',
        surrogateKeys: ['page-1'],
    );

    InvalidateFrontendSurrogateKeysAction::run(['page-1']);

    expect(Cache::has('fragment:local-fragment'))->toBeFalse();
    Bus::assertNotDispatched(FlushCdnPurgeBatchJob::class);
});

it('retains surrogate keys recorded while a CDN purge batch is in flight', function (): void {
    $buffer = resolve(CdnPurgeBuffer::class);
    $buffer->record(['site-1']);

    $batch = $buffer->snapshot();
    $buffer->record(['site-1', 'page-2']);

    $buffer->acknowledge($batch);

    expect($buffer->snapshot())->toBe(['site-1' => 1, 'page-2' => 1]);
});

it('limits CDN purge snapshots and retains the remainder', function (): void {
    $buffer = resolve(CdnPurgeBuffer::class);
    $keys = array_map(static fn (int $index): string => 'page-' . $index, range(1, CdnPurgeBuffer::BATCH_SIZE + 5));

    $buffer->record($keys);
    $batch = $buffer->snapshot();
    $buffer->acknowledge($batch);

    expect($batch)->toHaveCount(CdnPurgeBuffer::BATCH_SIZE)
        ->and($buffer->snapshot())->toHaveCount(5);
});
