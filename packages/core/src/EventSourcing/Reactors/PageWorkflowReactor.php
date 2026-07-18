<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Reactors;

use Capell\Core\Enums\ListenerEnum;
use Capell\Core\EventSourcing\Events\PageArchived;
use Capell\Core\EventSourcing\Events\PagePublishedNow;
use Capell\Core\EventSourcing\Events\PagePublishScheduled;
use Capell\Core\EventSourcing\Events\PageRolledBack;
use Capell\Core\EventSourcing\Events\PageUnpublished;
use Capell\Core\Facades\CapellCore;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Bridges editorial workflow + rollback events to the SubscriberRegistry so
 * other packages can react (evict caches, rebuild redirects, refresh the
 * beacon) without importing event-sourcing internals — honouring the Package
 * Independence boundary in the cross-package direction.
 *
 * Reactors do not run during replay, so these side-effects fire only when a
 * change actually happens, never when the stream is rebuilt.
 */
final class PageWorkflowReactor extends Reactor
{
    public function onPagePublishedNow(PagePublishedNow $event): void
    {
        $this->notify(ListenerEnum::PagePublished, $event);
    }

    public function onPagePublishScheduled(PagePublishScheduled $event): void
    {
        $this->notify(ListenerEnum::PagePublishScheduled, $event);
    }

    public function onPageUnpublished(PageUnpublished $event): void
    {
        $this->notify(ListenerEnum::PageUnpublished, $event);
    }

    public function onPageArchived(PageArchived $event): void
    {
        $this->notify(ListenerEnum::PageArchived, $event);
    }

    public function onPageRolledBack(PageRolledBack $event): void
    {
        $this->notify(ListenerEnum::PageRolledBack, $event);
    }

    private function notify(ListenerEnum $listener, ShouldBeStored $event): void
    {
        CapellCore::subscriberManager()->notifySubscribers($listener, $event);
    }
}
