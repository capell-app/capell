<?php

declare(strict_types=1);

namespace Capell\Core\Listeners;

use Capell\Core\Actions\ContentGraph\RebuildContentGraphForModelAction;
use Capell\Core\Contracts\EventSubscriber;
use Capell\Core\Enums\ListenerEnum;
use Capell\Core\EventSourcing\Events\PageRolledBack;
use Capell\Core\Models\Page;

final readonly class RebuildContentGraphOnPageRollbackSubscriber implements EventSubscriber
{
    public function handle(string $event, object $context): void
    {
        if ($event !== ListenerEnum::PageRolledBack->value || ! $context instanceof PageRolledBack) {
            return;
        }

        $aggregateUuid = $context->aggregateRootUuid();

        if ($aggregateUuid === null) {
            return;
        }

        $page = Page::query()->where('uuid', $aggregateUuid)->first();

        if ($page instanceof Page) {
            RebuildContentGraphForModelAction::run($page);
        }
    }
}
