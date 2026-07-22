<?php

declare(strict_types=1);

namespace Capell\Frontend\Jobs;

use Capell\Frontend\Support\Cache\CdnPurgeBuffer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

final class FlushCdnPurgeBatchJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 300;

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function uniqueId(): string
    {
        return 'cdn-purge-batch';
    }

    public function handle(CdnPurgeBuffer $buffer): void
    {
        $batch = $buffer->snapshot();

        if ($batch !== []) {
            (new PurgeCdnCacheJob(array_keys($batch)))->handle();
            $buffer->acknowledge($batch);

            if ($buffer->hasPending()) {
                self::dispatch()->delay(now()->addSeconds(2));
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        self::dispatch()->delay(now()->addMinutes(5));
    }
}
