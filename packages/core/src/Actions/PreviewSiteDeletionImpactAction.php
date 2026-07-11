<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Data\DeletionImpactData;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Translation;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static DeletionImpactData run(Site $site)
 */
final class PreviewSiteDeletionImpactAction
{
    use AsObject;

    public function handle(Site $site): DeletionImpactData
    {
        $pageIds = Page::query()
            ->whereBelongsTo($site)
            ->pluck((new Page)->getKeyName());

        return new DeletionImpactData(
            pages: $pageIds->count(),
            siteDomains: SiteDomain::query()->whereBelongsTo($site)->count(),
            layouts: Layout::query()->whereBelongsTo($site)->count(),
            pageUrls: PageUrl::query()->whereBelongsTo($site)->count(),
            translations: Translation::query()
                ->where(function (Builder $query) use ($pageIds, $site): void {
                    $query->where(function (Builder $query) use ($site): void {
                        $query->where('translatable_type', $site->getMorphClass())
                            ->where('translatable_id', $site->getKey());
                    })
                        ->orWhere(function (Builder $query) use ($pageIds): void {
                            $query->where('translatable_type', resolve(Page::class)->getMorphClass())
                                ->whereIn('translatable_id', $pageIds);
                        });
                })
                ->count(),
        );
    }
}
