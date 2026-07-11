<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Contracts;

/**
 * Marks a stored event that carries a full serialised aggregate state blob
 * (e.g. a recorded revision or a rollback marker). The rollback machinery
 * replays the stream and, for any chosen version, takes the state from the
 * most recent event at-or-before that version implementing this contract —
 * no per-field upcasting required, which is precisely why event sourcing can
 * replace coarse content snapshots without blueprint-schema migration pain.
 */
interface CarriesAggregateState
{
    /**
     * @return array<string, mixed>
     */
    public function state(): array;
}
