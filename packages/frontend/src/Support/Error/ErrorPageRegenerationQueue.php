<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Error;

/**
 * Request-scoped registry that collapses duplicate error-page regenerations.
 *
 * A single web request can save a Site plus several related models (domains,
 * translations), each of which would otherwise dispatch its own heavy
 * regeneration for the same site. Recording the first occurrence per site id
 * lets callers skip the redundant dispatches.
 */
final class ErrorPageRegenerationQueue
{
    /** @var array<int, true> */
    private array $queuedSiteIds = [];

    /**
     * Record the site id, returning true the first time it is seen and false
     * for every subsequent call within the same request.
     */
    public function markQueued(int $siteId): bool
    {
        if (array_key_exists($siteId, $this->queuedSiteIds)) {
            return false;
        }

        $this->queuedSiteIds[$siteId] = true;

        return true;
    }

    /**
     * @return array<int, int>
     */
    public function queuedSiteIds(): array
    {
        return array_keys($this->queuedSiteIds);
    }
}
