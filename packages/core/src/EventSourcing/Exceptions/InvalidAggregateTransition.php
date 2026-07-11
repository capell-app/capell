<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Exceptions;

/**
 * Thrown when an aggregate rejects a workflow transition that violates its
 * invariants (e.g. approving twice, scheduling in the past, publishing from
 * an archived state, or requesting changes without a note).
 */
class InvalidAggregateTransition extends EventSourcingException {}
