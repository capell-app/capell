<?php

declare(strict_types=1);

namespace Capell\Core\Observers;

use Capell\Core\Enums\CacheEnum;
use Capell\Core\Events\FrontendSurrogateKeysInvalidated;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Translation;
use Capell\Core\Support\CapellCoreHelper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class SiteObserver
{
    public function creating(Site $site): void
    {
        $blueprintId = $site->getAttribute('blueprint_id');

        if ($blueprintId === null || $blueprintId === 0) {
            $site->blueprint_id = Blueprint::query()->siteType()->default()->value('id');
            throw_unless($site->blueprint_id, InvalidArgumentException::class, 'Unable to create site without a type.');
        }
    }

    public function created(Site $site): void
    {
        Cache::forget(CacheEnum::TotalSites->value);
    }

    public function deleting(Site $site): void
    {
        if (! $site->isForceDeleting()) {
            return;
        }

        $pageIds = Page::withTrashed()
            ->whereBelongsTo($site)
            ->pluck((new Page)->getKeyName());

        Translation::withTrashed()
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
            ->forceDelete();

        PageUrl::withTrashed()
            ->whereBelongsTo($site)
            ->forceDelete();

        Page::withTrashed()
            ->whereBelongsTo($site)
            ->orderByDesc('_lft')
            ->get()
            ->each(fn (Page $page): ?bool => $page->forceDelete());

        Layout::withTrashed()
            ->whereBelongsTo($site)
            ->forceDelete();

        SiteDomain::withTrashed()
            ->whereBelongsTo($site)
            ->forceDelete();
    }

    public function saved(Site $site): void
    {
        CapellCoreHelper::flushCache([
            CacheEnum::Site,
            CacheEnum::AllSites,
            CacheEnum::HasDefaultSite,
            CacheEnum::SiteLanguages,
            CacheEnum::LanguageByIdOrSite,
            CacheEnum::RelationExists,
        ]);

        if ($site->wasChanged('theme_id')) {
            event(new FrontendSurrogateKeysInvalidated(['site-' . $site->getKey()]));
        }
    }

    public function deleted(Site $site): void
    {
        Cache::forget(CacheEnum::TotalSites->value);
        $this->saved($site);
    }

    public function restored(Site $site): void
    {
        $this->saved($site);
    }
}
