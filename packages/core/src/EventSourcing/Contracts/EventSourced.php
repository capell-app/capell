<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Contracts;

use Capell\Core\EventSourcing\Aggregates\CapellAggregateRoot;

/**
 * Implemented (via the IsEventSourced trait) by any model that opts into event
 * sourcing. A model author writes only the two declaration methods; the trait
 * supplies aggregateUuid(). This is the seam that keeps the engine reusable:
 * generic services depend on this contract, never on a concrete model.
 */
interface EventSourced
{
    /**
     * The stable aggregate identifier — the model's uuid.
     */
    public function aggregateUuid(): string;

    /**
     * @return class-string<CapellAggregateRoot>
     */
    public function eventSourcedAggregate(): string;

    public function eventSourcedSerializer(): EventSourcedStateSerializer;
}
