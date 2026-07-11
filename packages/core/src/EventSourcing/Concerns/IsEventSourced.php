<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Concerns;

use Capell\Core\EventSourcing\Aggregates\CapellAggregateRoot;
use Capell\Core\EventSourcing\Contracts\EventSourced;
use Capell\Core\EventSourcing\Contracts\EventSourcedStateSerializer;
use Capell\Core\EventSourcing\Exceptions\EventSourcingException;

/**
 * Opt-in trait that turns an Eloquent model into an event-sourced aggregate
 * subject. The model must expose a `uuid` attribute; the two declaration
 * methods (eventSourcedAggregate / eventSourcedSerializer) are the only thing
 * a model author writes.
 *
 * @phpstan-require-implements EventSourced
 */
trait IsEventSourced
{
    /**
     * @return class-string<CapellAggregateRoot>
     */
    abstract public function eventSourcedAggregate(): string;

    abstract public function eventSourcedSerializer(): EventSourcedStateSerializer;

    public function aggregateUuid(): string
    {
        $uuid = $this->getAttribute('uuid');

        if (! is_string($uuid) || $uuid === '') {
            throw new EventSourcingException(sprintf(
                '%s cannot act as an event-sourced aggregate without a uuid.',
                static::class,
            ));
        }

        return $uuid;
    }
}
