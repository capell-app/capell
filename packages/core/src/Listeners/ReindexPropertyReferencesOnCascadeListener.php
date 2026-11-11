<?php

declare(strict_types=1);

namespace Capell\Core\Listeners;

use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Models\Term;
use Capell\Core\Models\TermPropertyValue;
use Capell\Core\Models\Translation;
use Capell\Core\Support\ContentGraph\PropertyReferenceSyncScheduler;
use Illuminate\Database\Eloquent\Builder;

/**
 * Deleting a property definition or a page translation removes property values through database
 * cascades, which fire no model events, so the owners are re-indexed here and no edge outlives the
 * value it described.
 */
final class ReindexPropertyReferencesOnCascadeListener
{
    /**
     * Collects the owners whose referencing values the delete will cascade away and re-indexes them
     * once the request, job or command finishes, after the delete has committed. Nothing is removed
     * here, so a delete that fails or rolls back leaves every value in place; the deferred re-index
     * then reads the unchanged values and keeps their edges.
     */
    public function definitionDeleting(PropertyDefinition $definition): void
    {
        PagePropertyValue::query()
            ->where('property_definition_id', $definition->getKey())
            ->where(fn (Builder $query): Builder => $query->whereNotNull('media_id')->orWhereNotNull('referenced_page_id')->orWhereNotNull('term_id'))
            ->distinct()
            ->pluck('page_id')
            ->each(static fn (mixed $pageId) => PropertyReferenceSyncScheduler::schedule(Page::class, (int) $pageId));

        TermPropertyValue::query()
            ->where('property_definition_id', $definition->getKey())
            ->where(fn (Builder $query): Builder => $query->whereNotNull('media_id')->orWhereNotNull('referenced_page_id')->orWhereNotNull('referenced_term_id'))
            ->distinct()
            ->pluck('term_id')
            ->each(static fn (mixed $termId) => PropertyReferenceSyncScheduler::schedule(Term::class, (int) $termId));
    }

    public function translationDeleted(Translation $translation): void
    {
        if (! $translation->isPageable() || ! is_numeric($translation->getAttribute('translatable_id'))) {
            return;
        }

        PropertyReferenceSyncScheduler::schedule(Page::class, (int) $translation->getAttribute('translatable_id'));
    }
}
