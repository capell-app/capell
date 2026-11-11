<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Frontend\Jobs\FlushCdnPurgeBatchJob;
use Capell\Frontend\Jobs\PurgeCdnCacheJob;
use Capell\Frontend\Support\Cache\CdnPurgeBuffer;
use Capell\Frontend\Support\Cache\FragmentCache;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

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

        $failure = null;

        // One failed token write must not leave the remaining keys or the CDN purge undone.
        foreach ($surrogateKeys as $surrogateKey) {
            try {
                $fragmentCache->invalidateBySurrogateKey($surrogateKey);
            } catch (Throwable $exception) {
                $failure ??= $exception;
            }
        }

        $this->purgeCdn($surrogateKeys, $awaitCdn);

        throw_if($failure instanceof Throwable, $failure);
    }

    /** @param  array<int, string>  $surrogateKeys */
    private function purgeCdn(array $surrogateKeys, bool $awaitCdn): void
    {
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
