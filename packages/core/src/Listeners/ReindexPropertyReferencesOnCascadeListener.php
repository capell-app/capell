<?php

declare(strict_types=1);

namespace Capell\Core\Listeners;

use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\PropertyDefinition;
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
     * The definition's referencing values are removed through their models first, so each removal
     * schedules its owner's re-index; the database cascade then removes the remaining plain values.
     */
    public function definitionDeleting(PropertyDefinition $definition): void
    {
        PagePropertyValue::query()
            ->where('property_definition_id', $definition->getKey())
            ->where(fn (Builder $query): Builder => $query->whereNotNull('media_id')->orWhereNotNull('referenced_page_id')->orWhereNotNull('term_id'))
            ->get()
            ->each(static fn (PagePropertyValue $value): ?bool => $value->delete());

        TermPropertyValue::query()
            ->where('property_definition_id', $definition->getKey())
            ->where(fn (Builder $query): Builder => $query->whereNotNull('media_id')->orWhereNotNull('referenced_page_id')->orWhereNotNull('referenced_term_id'))
            ->get()
            ->each(static fn (TermPropertyValue $value): ?bool => $value->delete());
    }

    public function translationDeleted(Translation $translation): void
    {
        if (! $translation->isPageable() || ! is_numeric($translation->getAttribute('translatable_id'))) {
            return;
        }

        PropertyReferenceSyncScheduler::schedule(Page::class, (int) $translation->getAttribute('translatable_id'));
    }
}
