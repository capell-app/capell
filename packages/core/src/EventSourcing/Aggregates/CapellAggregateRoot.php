<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Aggregates;

use Capell\Core\EventSourcing\Exceptions\InvalidAggregateTransition;
use Illuminate\Support\Facades\Auth;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * Base aggregate adding Capell conventions on top of Spatie's AggregateRoot:
 *
 *  - actor stamping: the authenticated user id is written into every recorded
 *    event's metadata, so projections and the revision index know "who",
 *  - a transition guard helper for enforcing workflow invariants,
 *  - the revision/rollback recording contract the generic RollbackService and
 *    the recording bridge drive without knowing concrete event classes.
 *
 * This base class is the seam that would localise a future swap to a different
 * engine (e.g. Verbs): only this class and the event base would change.
 */
abstract class CapellAggregateRoot extends AggregateRoot
{
    public const string META_ACTOR_ID = 'actor_id';

    /**
     * Record a full serialised state revision of the aggregate.
     *
     * @param  array<string, mixed>  $state
     */
    abstract public function recordRevision(array $state): static;

    /**
     * Record a rollback to a prior version as a new forward event (append-only;
     * rollback never rewrites history) carrying the restored state.
     *
     * @param  array<string, mixed>  $state
     */
    abstract public function recordRollback(int $toVersion, array $state): static;

    /**
     * Metadata stamped onto every recorded event so projections and the
     * revision index know which user caused the change.
     *
     * Deliberately parameterless: Spatie resolves apply-handlers by parameter
     * arity + type, so a helper that accepted a single ShouldBeStored would be
     * mistaken for an event handler during replay. Recording methods call this
     * and stamp the result themselves.
     *
     * @return array<string, mixed>
     */
    protected function actorMetaData(): array
    {
        $actorId = Auth::id();

        return $actorId === null ? [] : [self::META_ACTOR_ID => $actorId];
    }

    /**
     * Guard a workflow transition; throws when the condition does not hold.
     */
    protected function guard(bool $condition, string $message): void
    {
        throw_unless($condition, InvalidAggregateTransition::class, $message);
    }
}
