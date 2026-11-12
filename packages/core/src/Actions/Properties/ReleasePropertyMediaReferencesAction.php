<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Properties;

use Capell\Core\Actions\ContentGraph\SyncPropertyReferenceGraphEdgesAction;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeKind;
use Capell\Core\Models\ContentGraphEdge;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\Term;
use Capell\Core\Models\TermPropertyValue;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Releases property values that pointed at a media item once its row has been permanently deleted,
 * then re-indexes their owners. Where the media table carries the foreign key the database has
 * already nulled the values, so the owners are found through their stored graph edges; elsewhere
 * the values are nulled here. Running after the delete means a delete that fails keeps them intact.
 */
final class ReleasePropertyMediaReferencesAction
{
    use AsFake;
    use AsObject;

    public function handle(int $mediaId): void
    {
        DB::transaction(function () use ($mediaId): void {
            $edges = ContentGraphEdge::query()
                ->where('target_type', Media::class)
                ->where('target_id', $mediaId)
                ->where('kind', ContentGraphEdgeKind::ReferencedByProperty)
                ->get(['source_type', 'source_id']);

            $pageIds = PagePropertyValue::query()->where('media_id', $mediaId)->distinct()->pluck('page_id')
                ->merge($edges->filter(static fn (ContentGraphEdge $edge): bool => is_a((string) $edge->getAttribute('source_type'), Page::class, true))->pluck('source_id'))
                ->map(static fn (mixed $id): int => (int) $id)
                ->unique()
                ->all();
            $termIds = TermPropertyValue::query()->where('media_id', $mediaId)->distinct()->pluck('term_id')
                ->merge($edges->filter(static fn (ContentGraphEdge $edge): bool => is_a((string) $edge->getAttribute('source_type'), Term::class, true))->pluck('source_id'))
                ->map(static fn (mixed $id): int => (int) $id)
                ->unique()
                ->all();

            if ($pageIds === [] && $termIds === []) {
                return;
            }

            PagePropertyValue::query()->where('media_id', $mediaId)->update(['media_id' => null]);
            TermPropertyValue::query()->where('media_id', $mediaId)->update(['media_id' => null]);

            foreach (Page::query()->withTrashed()->whereKey($pageIds)->get() as $page) {
                SyncPropertyReferenceGraphEdgesAction::run($page);
            }

            foreach (Term::query()->whereKey($termIds)->get() as $term) {
                SyncPropertyReferenceGraphEdgesAction::run($term);
            }
        });
    }
}
