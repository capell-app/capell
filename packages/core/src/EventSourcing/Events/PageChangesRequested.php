<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Editorial intent: a reviewer requested changes, with a required note.
 */
final class PageChangesRequested extends ShouldBeStored
{
    public function __construct(
        public readonly string $note,
    ) {}
}
