<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Serialises an event-sourced model (and the relations it owns) into a flat,
 * portable revision blob, and rehydrates that blob back onto the model.
 *
 * This is where "a rollback is made up of multiple relation data" lives: a
 * single capture()/restore() round-trip must reproduce byte-identical owned
 * state (row attributes + owned relation rows), independent of the database
 * driver, so a revision can be replayed or rolled back deterministically.
 */
interface EventSourcedStateSerializer
{
    /**
     * Capture the model and its owned relations into a revision blob.
     *
     * @return array<string, mixed>
     */
    public function capture(Model $model): array;

    /**
     * Rehydrate the model and its owned relations from a revision blob.
     * Implementations MUST perform their writes inside a database transaction.
     *
     * @param  array<string, mixed>  $state
     */
    public function restore(Model $model, array $state): void;
}
