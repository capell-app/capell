<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Properties;

use Capell\Core\Actions\ContentGraph\SyncPropertyReferenceGraphEdgesAction;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\Term;
use Capell\Core\Models\TermPropertyValue;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Clears property values that point at a media item about to be permanently
 * deleted, then re-indexes their owners, so no value is left holding the key
 * of a file that no longer exists. Matches the database's own null-on-delete
 * policy for installs where the media table carries the foreign key, and
 * enforces it where it cannot.
 */
final class ReleasePropertyMediaReferencesAction
{
    use AsFake;
    use AsObject;

    public function handle(Media $media): void
    {
        $mediaId = $media->getKey();

        DB::transaction(function () use ($mediaId): void {
            $pageIds = PagePropertyValue::query()->where('media_id', $mediaId)->distinct()->pluck('page_id')->all();
            $termIds = TermPropertyValue::query()->where('media_id', $mediaId)->distinct()->pluck('term_id')->all();

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
