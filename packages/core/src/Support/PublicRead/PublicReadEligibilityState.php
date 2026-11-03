<?php

declare(strict_types=1);

namespace Capell\Core\Support\PublicRead;

use Capell\Core\Data\PublicRead\PublicReadStampData;
use Carbon\CarbonImmutable;

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
final readonly class PublicReadEligibilityState
{
    public function __construct(
        private PublicPageReadEligibilityRegistry $registry,
        private PublicReadEligibilityRevision $revision,
    ) {}

    public function isRestricted(): bool
    {
        return $this->registry->hasContributors();
    }

    /**
     * The stamp to store with a projection built now, or null when the rules
     * cannot be read and nothing should be stored or served.
     */
    public function stamp(): ?PublicReadStampData
    {
        return $this->stampFor($this->revision->current());
    }

    /**
     * Like stamp(), but reads the revision again instead of this request's
     * copy. Use it to fence a long-running generation.
     */
    public function freshStamp(): ?PublicReadStampData
    {
        return $this->stampFor($this->revision->fresh());
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

        $current = $this->revision->current();

        if ($current === null || $revision !== $current) {
            return false;
        }

        return $validUntil === null || CarbonImmutable::now()->getTimestamp() < $validUntil;
    }

    private function stampFor(?string $revision): ?PublicReadStampData
    {
        if (! $this->isRestricted()) {
            return new PublicReadStampData(null, null);
        }

        if ($revision === null) {
            return null;
        }

        return new PublicReadStampData($revision, $this->registry->nextTransitionAfter(CarbonImmutable::now()));
    }
}
