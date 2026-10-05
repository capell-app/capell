<?php

declare(strict_types=1);

namespace Capell\Core\Actions\RuntimeRefresh;

use Illuminate\Contracts\Cache\Repository;
use Lorisleiva\Actions\Concerns\AsObject;

/** Uses the same shared-cache signal consumed by Laravel's queue workers. */
final class RestartQueueWorkersAction
{
    use AsObject;

    public function __construct(private readonly Repository $cache) {}

    public function handle(): void
    {
        $this->cache->forever('illuminate:queue:restart', now()->getTimestamp());
    }
}
