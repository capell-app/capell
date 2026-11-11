<?php

declare(strict_types=1);

namespace Capell\Core\Observers;

use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\Term;
use Capell\Core\Models\TermPropertyValue;
use Capell\Core\Support\ContentGraph\PropertyReferenceSyncScheduler;

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
        $value instanceof PagePropertyValue
            ? PropertyReferenceSyncScheduler::schedule(Page::class, $value->page_id)
            : PropertyReferenceSyncScheduler::schedule(Term::class, $value->term_id);
    }
}
