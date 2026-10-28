<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Dashboard;

use Capell\Core\Models\Page;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

final class BuildContentStatusAction
{
    /** @return array<string, int> */
    public function handle(Authenticatable $actor, ?int $siteId, ?string $language): array
    {
        $counts = ['draft' => 0, 'scheduled' => 0, 'published' => 0, 'expired' => 0];
        if (! Gate::forUser($actor)->allows('viewAny', Page::class)) {
            return $counts;
        }

        $pages = SiteAccess::forActor($actor)->query(Page::class)
            ->when($siteId !== null, fn (Builder $query): Builder => $query->where('site_id', $siteId))
            ->when($language !== null, fn (Builder $query): Builder => $query->whereHas('translations.language', fn (Builder $query): Builder => $query->where('code', $language)))
            ->lazy();
        foreach ($pages as $page) {
            if (Gate::forUser($actor)->allows('view', $page)) {
                $key = $page->publishVisibilityState()->value;
                if (array_key_exists($key, $counts)) {
                    $counts[$key]++;
                }
            }
        }

        return $counts;
    }
}
