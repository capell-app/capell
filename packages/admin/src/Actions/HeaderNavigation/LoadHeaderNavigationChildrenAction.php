<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\HeaderNavigation;

use BackedEnum;
use Capell\Admin\Data\HeaderNavigation\HeaderNavigationBranchData;
use Capell\Admin\Data\HeaderNavigation\HeaderNavigationPageNodeData;
use Capell\Admin\Support\HeaderNavigation\HeaderNavigationAccessResolver;
use Capell\Core\Models\Page;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsObject;

final class LoadHeaderNavigationChildrenAction
{
    use AsObject;

    public const string MODE_SITE_ROOT = 'site-root';

    public const string MODE_PAGE_CHILDREN = 'page-children';

    public function __construct(
        private readonly HeaderNavigationAccessResolver $accessResolver,
    ) {}

    public function handle(
        ?Authenticatable $actor,
        string $mode,
        int $siteId,
        ?int $parentId = null,
        int $page = 1,
        int $perPage = 10,
    ): HeaderNavigationBranchData {
        if (! $actor instanceof Authenticatable || ! $this->accessResolver->canUseSite($actor, $siteId)) {
            return new HeaderNavigationBranchData([], max(1, $page), $perPage, false);
        }

        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));

        /** @var Builder<Page> $query */
        $query = Page::query()
            ->with(['site', 'type.roleRestrictions', 'pageUrl.siteDomain'])
            ->ordered();

        $this->accessResolver->constrainPageQueryForActor($query, $actor, $siteId);

        match ($mode) {
            self::MODE_SITE_ROOT => $query->whereNull('parent_id'),
            self::MODE_PAGE_CHILDREN => $query->where('parent_id', $parentId),
            default => throw new InvalidArgumentException('Unsupported header navigation mode: ' . $mode),
        };

        /** @var Collection<int, Page> $records */
        $records = $query
            ->offset(($page - 1) * $perPage)
            ->limit($perPage + 1)
            ->get();

        $visiblePages = $records
            ->filter(fn (Page $page): bool => $this->accessResolver->canViewPage($actor, $page))
            ->filter(fn (Page $page): bool => $this->accessResolver->editUrlFor($page) !== null)
            ->values();

        $hasMore = $visiblePages->count() > $perPage || $records->count() > $perPage;
        $visiblePages = $visiblePages->take($perPage)->values();
        $childParentIds = $this->visibleChildParentIds($actor, $visiblePages);

        return new HeaderNavigationBranchData(
            nodes: array_values($visiblePages
                ->map(fn (Page $page): HeaderNavigationPageNodeData => $this->nodeFromPage($page, $childParentIds))
                ->all()),
            page: $page,
            perPage: $perPage,
            hasMore: $hasMore,
        );
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

    /**
     * @param  array<int, bool>  $childParentIds
     */
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
        $icon = $page->type?->admin['icon'] ?? null;

        if ($icon instanceof BackedEnum) {
            return (string) $icon->value;
        }

        return is_string($icon) && $icon !== '' ? $icon : null;
    }
}
