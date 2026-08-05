<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Evidence that a queue worker is really consuming the Marketplace queue.
 *
 * Nothing else can produce that evidence: configuration says what should be
 * running, and only a worker that actually ran can say that something is. Every
 * Marketplace job records it as it starts, and a scheduled probe dispatches a
 * job of its own so a quiet installation still reports the truth.
 */
final class MarketplaceWorkerHeartbeat
{
    public const string CACHE_KEY = 'capell-marketplace:worker-seen-at';

    public const int DEFAULT_STALE_AFTER_SECONDS = 300;

    public static function record(): void
    {
        try {
            // Kept twice as long as it stays fresh, so a stale heartbeat is
            // still readable as "a worker ran, but not recently" rather than
            // being indistinguishable from one that never ran at all.
            Cache::put(self::CACHE_KEY, now()->toIso8601String(), self::staleAfterSeconds() * 2);
        } catch (Throwable $throwable) {
            // A cache store that cannot be written is already reported by the
            // readiness shared-cache check. Failing an install over a missing
            // heartbeat would turn a diagnostic into an outage.
            report($throwable);
        }
    }

    public static function seenAt(): ?CarbonInterface
    {
        $seenAt = Cache::get(self::CACHE_KEY);

        if (! is_string($seenAt) || $seenAt === '') {
            return null;
        }

        try {
            return now()->parse($seenAt);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Directional on purpose. An absolute difference would read a timestamp from
     * the future — a node whose clock runs ahead, or one that wrote the record
     * and then had its clock corrected backwards — as freshly seen for as long
     * as the skew lasts, which is the one case where "a worker ran recently" is
     * least likely to be true. Only a heartbeat at or before now, within the
     * window, counts.
     */
    public static function isFresh(): bool
    {
        $seenAt = self::seenAt();

        if (! $seenAt instanceof CarbonInterface) {
            return false;
        }

        $now = now();

        return ! $seenAt->greaterThan($now)
            && $seenAt->greaterThanOrEqualTo($now->copy()->subSeconds(self::staleAfterSeconds()));
    }

    public static function staleAfterSeconds(): int
    {
        $configured = config(
            'capell-marketplace.marketplace.worker_heartbeat_stale_after_seconds',
            self::DEFAULT_STALE_AFTER_SECONDS,
        );

        return is_numeric($configured) && (int) $configured > 0
            ? (int) $configured
            : self::DEFAULT_STALE_AFTER_SECONDS;
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
