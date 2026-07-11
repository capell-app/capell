<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Listeners;

use Capell\Core\Events\PageSaved;
use Capell\Core\EventSourcing\Contracts\EventSourced;
use Capell\Core\EventSourcing\Support\EventSourcedRegistry;
use Illuminate\Database\Eloquent\Model;

/**
 * The recording bridge: after a page is saved (the existing transient PageSaved
 * event), append a revision to its aggregate. No new save paths are introduced
 * — saves still go through Eloquent and event sourcing records *after* the write.
 *
 * Restores run event-silent (Model::withoutEvents) so this never fires during a
 * rollback, which would otherwise record a spurious revision.
 */
final class RecordPageRevision
{
    public function __construct(
        private readonly EventSourcedRegistry $registry,
    ) {}

    public function handle(PageSaved $event): void
    {
        $page = $event->page;

        if (! $page instanceof Model || ! $page instanceof EventSourced) {
            return;
        }

        if (! $this->registry->isRegistered($page)) {
            return;
        }

        $aggregateClass = $this->registry->aggregateFor($page);

        $aggregateClass::retrieve($page->aggregateUuid())
            ->recordRevision($this->registry->serializerFor($page)->capture($page))
            ->persist();
    }
}
