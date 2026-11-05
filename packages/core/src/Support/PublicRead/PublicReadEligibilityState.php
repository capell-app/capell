<?php

declare(strict_types=1);

namespace Capell\Core\Support\PublicRead;

use Capell\Core\Data\PublicRead\PublicReadStampData;
use Capell\Core\Jobs\PublicRead\ExpirePublicReadProjectionsJob;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Tells stored public projections which rules they are built under and
 * whether a stored copy may still be served.
 *
 * - With no package restricting public reads, every stored copy is current.
 * - Otherwise a copy is current only while the eligibility revision is
 *   unchanged and its earliest scheduled transition has not been reached.
 * - A revision that cannot be read makes nothing current (fail closed).
 *
 * @experimental
 */
final class PublicReadEligibilityState
{
    public const string EXPIRY_SCHEDULED_PREFIX = 'capell:public-read-eligibility:expiry-scheduled:';

    /** @var array<int, true> */
    private array $scheduled = [];

    public function __construct(
        private readonly PublicPageReadEligibilityRegistry $registry,
        private readonly PublicReadEligibilityRevision $revision,
    ) {}

    public function isRestricted(): bool
    {
        return $this->registry->hasContributors();
    }

    /**
     * The stamp to store with a projection built now, or null when the rules
     * cannot be read and nothing should be stored or served.
     */
    public function stamp(?int $siteId = null): ?PublicReadStampData
    {
        if (! $this->isRestricted()) {
            return new PublicReadStampData(null, null);
        }

        resolve(PublicReadResponseStamps::class)->record($siteId);

        return $this->stampFor($this->revision->current(), $siteId);
    }

    /**
     * Like stamp(), but reads the revision again instead of this request's
     * copy. Use it to fence a long-running generation.
     */
    public function freshStamp(?int $siteId = null): ?PublicReadStampData
    {
        if (! $this->isRestricted()) {
            return new PublicReadStampData(null, null);
        }

        resolve(PublicReadResponseStamps::class)->record($siteId);

        return $this->stampFor($this->revision->fresh(), $siteId);
    }

    /**
     * Whether a projection stored with this revision and validity may still
     * be served.
     */
    public function isCurrent(?string $revision, ?int $validUntil): bool
    {
        if (! $this->isRestricted()) {
            return true;
        }

        // A stored copy served now is bounded by the rules of every site.
        resolve(PublicReadResponseStamps::class)->record(null);

        $current = $this->revision->current();

        if ($current === null || $revision !== $current) {
            return false;
        }

        return $validUntil === null || CarbonImmutable::now()->getTimestamp() < $validUntil;
    }

    /**
     * With a site, valid_until is the next transition that can affect that
     * site's pages, so a schedule on one site never bounds copies of another.
     * With null it is the next transition on any site.
     */
    private function stampFor(?string $revision, ?int $siteId): ?PublicReadStampData
    {
        if ($revision === null) {
            return null;
        }

        $now = CarbonImmutable::now();
        $validUntil = $this->registry->nextTransitionAfter($now, $siteId);

        // A schedule that cannot be read answers "now": everything built now
        // is already expired (deny), and there is no future moment to clean
        // up at, so nothing is queued for it.
        if ($validUntil instanceof CarbonImmutable && $validUntil->greaterThan($now)) {
            $this->scheduleExpiry($validUntil);
        }

        return new PublicReadStampData($revision, $validUntil);
    }

    /**
     * Cleanup only, never the safety mechanism: copies served through
     * Laravel check the stamp on every request, and nothing whose stamp has a
     * valid_until may be served without Laravel. This job withdraws edge
     * copies and triggers regeneration at the transition, without waiting for
     * the scheduler. A synchronous queue would run it at once, before the
     * transition, so it is left to the scheduler there. When the dispatch
     * fails, its once-per-transition marker is removed so a later request
     * schedules it again.
     */
    private function scheduleExpiry(CarbonImmutable $validUntil): void
    {
        $at = $validUntil->getTimestamp();

        if (isset($this->scheduled[$at])) {
            return;
        }

        $this->scheduled[$at] = true;

        if ($this->queueRunsJobsImmediately()) {
            return;
        }

        $marker = self::EXPIRY_SCHEDULED_PREFIX . $at;

        try {
            if (! Cache::add($marker, true, $validUntil->addMinutes(5))) {
                return;
            }
        } catch (Throwable $throwable) {
            report($throwable);

            return;
        }

        try {
            resolve(Dispatcher::class)->dispatch(new ExpirePublicReadProjectionsJob($at)->delay($validUntil));
        } catch (Throwable $throwable) {
            report($throwable);

            try {
                Cache::forget($marker);
            } catch (Throwable $forgetFailure) {
                report($forgetFailure);
            }
        }
    }

    private function queueRunsJobsImmediately(): bool
    {
        $connection = config('queue.default');
        $driver = is_string($connection) ? config('queue.connections.' . $connection . '.driver') : null;

        return $driver === 'sync' || $driver === 'null';
    }
}
