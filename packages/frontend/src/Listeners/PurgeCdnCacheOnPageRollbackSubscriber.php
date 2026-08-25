<?php

declare(strict_types=1);

namespace Capell\Frontend\Listeners;

use Capell\Core\Contracts\EventSubscriber;
use Capell\Core\Enums\ListenerEnum;
use Capell\Core\EventSourcing\Events\PageRolledBack;
use Capell\Core\Models\Page;
use Capell\Frontend\Actions\PurgeCdnCacheByPageAction;
use Capell\Frontend\Support\Cache\CacheInvalidationRegistry;

final readonly class PurgeCdnCacheOnPageRollbackSubscriber implements EventSubscriber
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

        if (! $page instanceof Page) {
            return;
        }

        resolve(CacheInvalidationRegistry::class)->invalidateChangedModel($page);
        PurgeCdnCacheByPageAction::run($page);
    }
}
