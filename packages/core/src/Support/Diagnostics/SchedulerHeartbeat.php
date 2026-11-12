<?php

declare(strict_types=1);

namespace Capell\Core\Support\Diagnostics;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Throwable;

/**
 * Evidence that Laravel's scheduler is running.
 *
 * Core schedules record() every five minutes. A missing or stale timestamp
 * means nothing is invoking schedule:run, which on its own looks like a site
 * that works: pages render while scheduled publishing, pruning and retries
 * silently stop. The timestamp lives in the default cache store, so every
 * application node reads the same value when that store is shared.
 */
final readonly class SchedulerHeartbeat
{
    public const string CACHE_KEY = 'capell:scheduler:heartbeat';

    public const string SCHEDULE_NAME = 'capell:scheduler-heartbeat';

    /** Three missed five-minute beats before the scheduler is reported as stopped. */
    public const int STALE_AFTER_SECONDS = 900;

    public function __construct(private Repository $cache) {}

    public function record(): void
    {
        $this->cache->forever(self::CACHE_KEY, CarbonImmutable::now()->getTimestamp());
    }

    /**
     * Null when no beat was recorded or the cache cannot be read. An unreadable
     * cache is reported by the cache checks; here it only means there is no
     * evidence that the scheduler ran.
     */
    public function lastBeatAt(): ?CarbonImmutable
    {
        try {
            $timestamp = $this->cache->get(self::CACHE_KEY);
        } catch (Throwable) {
            return null;
        }

        return is_int($timestamp) ? CarbonImmutable::createFromTimestamp($timestamp) : null;
    }

    public function isStale(CarbonImmutable $beatAt): bool
    {
        return $beatAt->diffInSeconds(CarbonImmutable::now()) > self::STALE_AFTER_SECONDS;
    }
}
