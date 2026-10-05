<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Listeners;

use Capell\Core\Events\PageSaved;
use Capell\Core\EventSourcing\Contracts\EventSourced;
use Capell\Core\EventSourcing\Support\EventSourcedRegistry;
use Capell\Core\Models\Page;
use Illuminate\Database\Eloquent\Model;

/**
 * The recording bridge: after a page is saved (the existing transient PageSaved
 * event), append a revision to its aggregate. No new save paths are introduced
 * — saves still go through Eloquent and event sourcing records *after* the write.
 *
 * Snapshot replay is event-silent. Trash restoration changes availability alone
 * and does not create another identical content revision.
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

        // Restoration changes availability, not the content captured by a revision.
        // Retain revision recording if a normal model listener also changes Page attributes.
        if (($event->formData['_restored'] ?? false) === true && $page instanceof Page && $page->wasChanged('deleted_at') && ! $page->trashed()
            && array_diff(array_keys($page->getChanges()), ['deleted_at', 'updated_at', 'updated_by']) === []) {
            return;
        }

        // A page's initial Eloquent save can happen before the authoring flow
        // persists its owned translations. The flow dispatches PageSaved again
        // after relationships exist, which is the first complete aggregate
        // state that can safely be exposed as a rollback target.
        if ($page->wasRecentlyCreated && $page->translations()->doesntExist()) {
            return;
        }

        $aggregateClass = $this->registry->aggregateFor($page);

        $aggregateClass::retrieve($page->aggregateUuid())
            ->recordRevision($this->registry->serializerFor($page)->capture($page))
            ->persist();
    }
}
