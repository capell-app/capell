<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Contracts;

/**
 * Marks a stored event whose state was restored *from* an earlier version —
 * i.e. a rollback marker. While {@see CarriesAggregateState} answers "what
 * state does this event hold?", this answers "which version's content does
 * this event make live?". The rollback engine uses it to derive the active
 * content version (the head event normally, but its origin version when the
 * head is itself a rollback), which in turn tells the UI whether restoring a
 * given version means going backward or forward — without the engine ever
 * needing to know about a concrete page event type.
 */
interface CarriesRollbackOrigin
{
    public function rolledBackToVersion(): int;
}
