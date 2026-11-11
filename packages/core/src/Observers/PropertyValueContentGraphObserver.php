<?php

declare(strict_types=1);

namespace Capell\Core\Observers;

use Capell\Core\Actions\ContentGraph\SyncPropertyReferenceGraphEdgesAction;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\Term;
use Capell\Core\Models\TermPropertyValue;

/**
 * Keeps an owner's property-reference edges current whenever one of its
 * values is written or removed, whichever action or import wrote it. Many
 * value writes in one request collapse into one re-index per owner.
 */
final class PropertyValueContentGraphObserver
{
    public function saved(PagePropertyValue|TermPropertyValue $value): void
    {
        $this->scheduleSync($value);
    }

    public function deleted(PagePropertyValue|TermPropertyValue $value): void
    {
        $this->scheduleSync($value);
    }

    private function scheduleSync(PagePropertyValue|TermPropertyValue $value): void
    {
        $isPageValue = $value instanceof PagePropertyValue;
        $ownerId = $isPageValue ? $value->page_id : $value->term_id;

        defer(
            static function () use ($isPageValue, $ownerId): void {
                // A trashed page keeps its references so a restore brings them back.
                $owner = $isPageValue
                    ? Page::query()->withTrashed()->find($ownerId)
                    : Term::query()->find($ownerId);

                if ($owner instanceof Page || $owner instanceof Term) {
                    SyncPropertyReferenceGraphEdgesAction::run($owner);
                }
            },
            'content-graph:property-references:' . ($isPageValue ? Page::class : Term::class) . ':' . $ownerId,
            always: true,
        );
    }
}
