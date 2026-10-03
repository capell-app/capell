<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Enums\ContentGraph\ContentGraphEdgeStrength;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\ContentGraphEdge;
use Capell\Core\Models\EditorScratchDraft;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Taxonomy;
use Capell\Core\Models\TermPropertyValue;
use Capell\Core\Models\Theme;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsObject;

/** Integrity checks include all sites and retained trash, independently of author visibility. */
final class HasRetainedDeletionDependenciesAction
{
    use AsObject;

    public function handle(Model $record): bool
    {
        return match (true) {
            $record instanceof Site => $record->pages()->withTrashed()->exists()
                || $record->siteDomains()->withTrashed()->exists()
                || $record->layouts()->withTrashed()->exists()
                || PageUrl::withTrashed()->where('site_id', $record->getKey())->exists()
                || Taxonomy::query()->where('site_id', $record->getKey())->exists()
                || EditorScratchDraft::query()->where('site_id', $record->getKey())->exists(),
            // Nested-set deletion removes descendants outside the selected-record guards.
            // Require authors to remove children explicitly before their parent.
            $record instanceof Page => $record->descendants()->getQuery()->withTrashed()->exists()
                || $record->canonicalPages()->withTrashed()->exists()
                || PagePropertyValue::query()->where('referenced_page_id', $record->getKey())
                    ->where('page_id', '!=', $record->getKey())->exists()
                || TermPropertyValue::query()->where('referenced_page_id', $record->getKey())->exists(),
            $record instanceof Layout => $record->pages()->withTrashed()->exists(),
            $record instanceof Theme => $record->sites()->withTrashed()->exists()
                || $record->layouts()->withTrashed()->exists(),
            $record instanceof Blueprint => $record->pages()->withTrashed()->exists()
                || $record->sites()->withTrashed()->exists()
                || $record->themes()->withTrashed()->exists(),
            $record instanceof Language => $record->sitesLanguage()->withTrashed()->exists()
                || $record->sites()->withTrashedParents()->withTrashed()->exists()
                || PageUrl::withTrashed()->where('language_id', $record->getKey())->exists()
                || $record->translations()->withTrashed()->exists(),
            default => false,
        } || $this->hasRetainedGraphDependants($record);
    }

    private function hasRetainedGraphDependants(Model $record): bool
    {
        $edges = ContentGraphEdge::query()
            ->where('target_type', $record::class)
            ->where('target_id', $record->getKey())
            ->where('strength', ContentGraphEdgeStrength::Strong)
            ->get(['source_type', 'source_id']);

        foreach ($edges->groupBy('source_type') as $sourceType => $sourceEdges) {
            if (! is_string($sourceType)) {
                continue;
            }

            if (! is_a($sourceType, Model::class, true)) {
                continue;
            }

            // Integrity is independent of soft deletion, tenancy and author visibility.
            $query = $sourceType::query()->withoutGlobalScopes()->whereKey($sourceEdges->pluck('source_id')->all());
            if ($record instanceof Page && is_a($sourceType, PageUrl::class, true)) {
                // A Page's own URLs are removed by its observer, so are not retained.
                $query->where(fn (Builder $query): Builder => $query->whereNull('pageable_type')
                    ->orWhere('pageable_type', '!=', $record->getMorphClass())
                    ->orWhereNull('pageable_id')
                    ->orWhere('pageable_id', '!=', $record->getKey()));
            }

            if ($query->exists()) {
                return true;
            }
        }

        return false;
    }
}
