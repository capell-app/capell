<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use BackedEnum;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Core\Enums\AssetEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Page;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildPageTreeViewDataAction
{
    use AsFake;
    use AsObject;

    /**
     * @return array{
     *     record: Page,
     *     home: Page|null,
     *     siblings: Collection<int, Page>,
     *     children: Collection<int, Page>,
     *     ancestors: Collection<int, Page>,
     *     resourceClass: class-string,
     *     resourceIcon: BackedEnum|string|null
     * }
     */
    public function handle(Page $record): array
    {
        $relations = ['ancestors.pageUrl.siteDomain', 'blueprint', 'pageUrl.siteDomain'];

        $record->loadMissing(['site.language', ...$relations]);

        $type = $record->blueprint->admin['resource'] ?? 'default';
        $home = Page::getSiteHomePage($record->site, relations: ['blueprint', 'pageUrl.siteDomain']);

        if ($home instanceof Page) {
            $home->loadMissing(['blueprint', 'pageUrl.siteDomain']);
        }

        $ancestorIds = SiteAccess::current()->scope($record->ancestors()->getQuery())->get(['id'])->pluck('id')->all();
        $ancestors = $ancestorIds === []
            ? new Collection
            : SiteAccess::current()->query(Page::class)
                ->with($relations)
                ->whereKey($ancestorIds)
                ->get()
                ->sortBy(function (Page $page) use ($ancestorIds): int {
                    $position = array_search($page->getKey(), $ancestorIds, true);

                    return is_int($position) ? $position : PHP_INT_MAX;
                })
                ->values();

        return [
            'record' => $record,
            'home' => $home,
            'siblings' => SiteAccess::current()->scope($record->siblings()->getQuery())->with($relations)->get(),
            'children' => SiteAccess::current()->scope($record->children()->getQuery())->with($relations)->get(),
            'ancestors' => $ancestors,
            'resourceClass' => AdminSurfaceLookup::resource(ResourceEnum::Page, $type),
            'resourceIcon' => CapellCore::getAsset(AssetEnum::Page)->getIcon(),
        ];
    }
}
