<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\HeaderNavigation;

use Capell\Admin\Data\HeaderNavigation\HeaderNavigationBranchData;
use Capell\Admin\Support\HeaderNavigation\HeaderNavigationAccessResolver;
use Capell\Admin\Support\HeaderNavigation\HeaderNavigationPageNodeBuilder;
use Capell\Core\Models\Page;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class LoadHeaderNavigationChildrenAction
{
    use AsFake;
    use AsObject;

    public const string MODE_SITE_ROOT = 'site-root';

    public const string MODE_PAGE_CHILDREN = 'page-children';

    public function __construct(
        private readonly HeaderNavigationAccessResolver $accessResolver,
        private readonly HeaderNavigationPageNodeBuilder $nodeBuilder,
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
            ->with(['site', 'blueprint.roleRestrictions', 'pageUrl.siteDomain'])
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

        return new HeaderNavigationBranchData(
            nodes: $this->nodeBuilder->build($actor, $visiblePages),
            page: $page,
            perPage: $perPage,
            hasMore: $hasMore,
        );
    }
}
