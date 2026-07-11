<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Events;

use Capell\Core\EventSourcing\Contracts\CarriesAggregateState;
use Capell\Core\EventSourcing\Contracts\CarriesRollbackOrigin;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Recorded as a new forward event when a page is rolled back to an earlier
 * version. Rollback is append-only — history is never rewritten — so this
 * carries the restored state and the version it was restored from.
 */
final class PageRolledBack extends ShouldBeStored implements CarriesAggregateState, CarriesRollbackOrigin
{
    /**
     * @param  array<string, mixed>  $state
     */
    public function __construct(
        public readonly int $toVersion,
        public readonly array $state,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function state(): array
    {
        return $this->state;
    }

    public function rolledBackToVersion(): int
    {
        return $this->toVersion;
    }
}
