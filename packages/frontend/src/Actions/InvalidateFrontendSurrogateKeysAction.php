<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Frontend\Jobs\FlushCdnPurgeBatchJob;
use Capell\Frontend\Jobs\PurgeCdnCacheJob;
use Capell\Frontend\Support\Cache\CdnPurgeBuffer;
use Capell\Frontend\Support\Cache\FragmentCache;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class InvalidateFrontendSurrogateKeysAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<int, string>  $surrogateKeys
     */
    public function handle(array $surrogateKeys, bool $awaitCdn = false): void
    {
        if ($surrogateKeys === []) {
            return;
        }

        $fragmentCache = resolve(FragmentCache::class);

        foreach ($surrogateKeys as $surrogateKey) {
            $fragmentCache->invalidateBySurrogateKey($surrogateKey);
        }

        if (! PurgeCdnCacheJob::hasConfiguredProvider()) {
            return;
        }

        if ($awaitCdn) {
            // Privacy remediation must observe delivery, including failure,
            // before its caller can certify completion. Soft purges retain stale bytes.
            new PurgeCdnCacheJob($surrogateKeys)->purgeImmediately();

            return;
        }

        $queue = config('capell-frontend.purge_queue', 'cdn');
        resolve(CdnPurgeBuffer::class)->record($surrogateKeys);

        dispatch(new FlushCdnPurgeBatchJob)
            ->onQueue(is_string($queue) ? $queue : 'cdn')
            ->delay(now()->addSeconds(2))
            ->afterCommit();
    }
}
