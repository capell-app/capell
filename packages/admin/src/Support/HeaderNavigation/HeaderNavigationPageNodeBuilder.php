<?php

declare(strict_types=1);

namespace Capell\Admin\Support\HeaderNavigation;

use BackedEnum;
use Capell\Admin\Data\HeaderNavigation\HeaderNavigationPageNodeData;
use Capell\Core\Models\Page;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final readonly class HeaderNavigationPageNodeBuilder
{
    public function __construct(
        private HeaderNavigationAccessResolver $accessResolver,
    ) {}

    /**
     * @param  Collection<int, Page>  $pages
     * @return list<HeaderNavigationPageNodeData>
     */
    public function build(Authenticatable $actor, Collection $pages): array
    {
        $childParentIds = $this->visibleChildParentIds($actor, $pages);

        return array_values($pages
            ->map(fn (Page $page): HeaderNavigationPageNodeData => $this->nodeFromPage($page, $childParentIds))
            ->all());
    }

    /**
     * @param  Collection<int, Page>  $pages
     * @return array<int, bool>
     */
    private function visibleChildParentIds(Authenticatable $actor, Collection $pages): array
    {
        $pageIds = $pages
            ->pluck('id')
            ->map(fn (int|string $pageId): int => (int) $pageId)
            ->values()
            ->all();

        if ($pageIds === []) {
            return [];
        }

        $siteIds = array_values($pages
            ->pluck('site_id')
            ->map(fn (int|string $siteId): int => (int) $siteId)
            ->unique()
            ->values()
            ->all());

        /** @var Builder<Page> $query */
        $query = Page::query()
            ->select(['id', 'parent_id', 'site_id', 'blueprint_id'])
            ->whereIn('parent_id', $pageIds);

        $this->accessResolver->constrainPageQueryForSites($query, $actor, $siteIds);

        return $query
            ->distinct()
            ->pluck('parent_id')
            ->mapWithKeys(fn (int|string|null $parentId): array => [(int) $parentId => true])
            ->all();
    }

    /** @param array<int, bool> $childParentIds */
    private function nodeFromPage(Page $page, array $childParentIds): HeaderNavigationPageNodeData
    {
        return new HeaderNavigationPageNodeData(
            id: (int) $page->getKey(),
            siteId: (int) $page->site_id,
            parentId: $page->parent_id === null ? null : (int) $page->parent_id,
            name: (string) $page->name,
            typeIcon: $this->resolveTypeIcon($page),
            editUrl: (string) $this->accessResolver->editUrlFor($page),
            publicUrl: $this->accessResolver->publicUrlFor($page),
            hasChildren: isset($childParentIds[(int) $page->getKey()]),
        );
    }

    private function resolveTypeIcon(Page $page): ?string
    {
        $icon = $page->blueprint?->admin['icon'] ?? null;

        if ($icon instanceof BackedEnum) {
            return (string) $icon->value;
        }

        return is_string($icon) && $icon !== '' ? $icon : null;
    }
}
