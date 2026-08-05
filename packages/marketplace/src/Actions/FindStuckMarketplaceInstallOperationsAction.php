<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Every operation that has stopped moving, whether or not it ever started.
 *
 * In-flight work goes stale by falling silent: it was claimed, then the
 * heartbeat stopped. Queued work goes stale by never being claimed at all,
 * which produces no error, no timeline entry, and no failed job — on a host
 * with no worker running, the operation simply waits forever while the doctor
 * reports a healthy system. Only elapsed time can detect that, so it is
 * detected here rather than left as the one failure mode with no signal.
 */
final class FindStuckMarketplaceInstallOperationsAction
{
    use AsFake;
    use AsObject;

    public const int DEFAULT_QUEUED_STALE_AFTER_SECONDS = 120;

    public static function queuedStaleAfterSeconds(): int
    {
        $configured = config(
            'capell-marketplace.marketplace.queued_stale_after_seconds',
            self::DEFAULT_QUEUED_STALE_AFTER_SECONDS,
        );

        return is_numeric($configured) && (int) $configured > 0
            ? (int) $configured
            : self::DEFAULT_QUEUED_STALE_AFTER_SECONDS;
    }

    /**
     * The in-memory twin of unclaimedQueuedOperations()'s age test, for callers
     * holding an attempt rather than building a query — the operations widget
     * on each poll, and the job deciding whether nothing ever claimed it.
     *
     * It exists so the age rule and the queued_at/created_at fallback are
     * written once. MarketplaceQueuedStalePredicateAgreementTest pins the two
     * halves to the same answer.
     */
    public static function isQueuedStale(MarketplaceInstallAttempt $attempt, ?CarbonInterface $now = null): bool
    {
        $queuedAt = $attempt->queued_at ?? $attempt->created_at;

        if (! $queuedAt instanceof CarbonInterface) {
            return false;
        }

        return $queuedAt->lessThan(($now ?? now())->subSeconds(max(1, self::queuedStaleAfterSeconds())));
    }

    /** @return Collection<int, MarketplaceInstallAttempt> */
    public function handle(int $staleAfterMinutes = 15, ?int $queuedStaleAfterSeconds = null): Collection
    {
        $staleBefore = now()->subMinutes(max(1, $staleAfterMinutes));
        $queuedStaleBefore = now()->subSeconds(max(1, $queuedStaleAfterSeconds ?? self::queuedStaleAfterSeconds()));

        return MarketplaceInstallAttempt::query()
            ->where(function (Builder $query) use ($staleBefore, $queuedStaleBefore): void {
                $query
                    ->where(fn (Builder $inFlight): Builder => $this->silentInFlightOperations($inFlight, $staleBefore))
                    ->orWhere(fn (Builder $queued): Builder => $this->unclaimedQueuedOperations($queued, $queuedStaleBefore));
            })
            ->oldest('started_at')
            ->get();
    }

    /**
     * @param  Builder<MarketplaceInstallAttempt>  $query
     * @return Builder<MarketplaceInstallAttempt>
     */
    private function silentInFlightOperations(Builder $query, CarbonInterface $staleBefore): Builder
    {
        return $query
            ->whereIn('status', [
                MarketplaceInstallIntentStatus::Running->value,
                MarketplaceInstallIntentStatus::CancelRequested->value,
            ])
            ->where(function ($silent) use ($staleBefore): void {
                $silent
                    ->where('heartbeat_at', '<', $staleBefore)
                    ->orWhere(function ($neverBeat) use ($staleBefore): void {
                        $neverBeat
                            ->whereNull('heartbeat_at')
                            ->where('started_at', '<', $staleBefore);
                    });
            });
    }

    /**
     * queued_at is stamped when the job is dispatched, so an attempt that has
     * none was never dispatched at all — its creation time is then the only
     * age there is, and it is just as stuck.
     *
     * @param  Builder<MarketplaceInstallAttempt>  $query
     * @return Builder<MarketplaceInstallAttempt>
     */
    private function unclaimedQueuedOperations(Builder $query, CarbonInterface $queuedStaleBefore): Builder
    {
        return $query
            ->where('status', MarketplaceInstallIntentStatus::Queued->value)
            ->where(function ($unclaimed) use ($queuedStaleBefore): void {
                $unclaimed
                    ->where('queued_at', '<', $queuedStaleBefore)
                    ->orWhere(function ($neverQueued) use ($queuedStaleBefore): void {
                        $neverQueued
                            ->whereNull('queued_at')
                            ->where('created_at', '<', $queuedStaleBefore);
                    });
            });
    }
}
