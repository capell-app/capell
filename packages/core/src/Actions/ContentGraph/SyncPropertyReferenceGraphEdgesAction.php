<?php

declare(strict_types=1);

namespace Capell\Core\Actions\ContentGraph;

use Capell\Core\Enums\ContentGraph\ContentGraphEdgeKind;
use Capell\Core\Models\ContentGraphEdge;
use Capell\Core\Models\Page;
use Capell\Core\Models\Term;
use Capell\Core\Support\ContentGraph\Extractors\PagePropertyValueContentGraphExtractor;
use Capell\Core\Support\ContentGraph\Extractors\TermPropertyValueContentGraphExtractor;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Re-indexes only the property-value references of one page or term.
 *
 * Property values change far more often than the page around them, and an
 * upgrade must index existing values without running every extension's
 * extractor, so this leaves the owner's other edges untouched.
 */
class SyncPropertyReferenceGraphEdgesAction
{
    use AsFake;
    use AsObject;

    public function handle(Page|Term $owner): void
    {
        $extractor = $owner instanceof Page
            ? new PagePropertyValueContentGraphExtractor
            : new TermPropertyValueContentGraphExtractor;

        DB::transaction(function () use ($owner, $extractor): void {
            ContentGraphEdge::query()
                ->where('source_type', $owner::class)
                ->where('source_id', $owner->getKey())
                ->where('kind', ContentGraphEdgeKind::ReferencedByProperty->value)
                ->delete();

            foreach ($extractor->extract($owner)->edges as $edge) {
                StoreContentGraphEdgeAction::run($edge);
            }
        });
    }
}
