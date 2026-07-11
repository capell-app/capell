<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Events;

use Capell\Core\EventSourcing\Contracts\CarriesAggregateState;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * A full serialised snapshot of the page aggregate at save time: page
 * attributes + per-translation content/title/meta + pageUrls + media refs +
 * tree position. Its payload is a strict superset of the legacy
 * PageContentSnapshot, which is why event sourcing can retire that subsystem.
 */
final class PageRevisionRecorded extends ShouldBeStored implements CarriesAggregateState
{
    /**
     * @param  array<string, mixed>  $state
     */
    public function __construct(
        public readonly array $state,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function state(): array
    {
        return $this->state;
    }
}
