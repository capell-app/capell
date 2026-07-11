<?php

declare(strict_types=1);

namespace Capell\Admin\Http\Controllers;

use Capell\Admin\Actions\HeaderNavigation\LoadHeaderNavigationChildrenAction;
use Capell\Admin\Actions\PageTree\LoadPageTreeBranchAction;
use Capell\Admin\Support\PageUrlPresenter;
use Capell\Core\Models\Page;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Internal API for lazy-loading page tree children.
 *
 * Consumed by the admin page tree Alpine.js component only.
 * All routes must be gated by the `auth` + global-admin middleware.
 */
class PageTreeController
{
    public function children(Request $request): JsonResponse
    {
        $actor = $this->authorizeAdminPanelAccess($request);

        if ($this->shouldUsePaginatedTree($request)) {
            return $this->paginatedChildren($request, $actor);
        }

        $parentId = $request->integer('parent');
        $siteId = $request->integer('site_id');

        $branch = LoadPageTreeBranchAction::make();

        $children = $branch
            ->handle($actor, $parentId, $siteId)
            ->map(fn (Page $page): array => [
                'id' => $page->id,
                'name' => $page->name,
                'parent_id' => $page->parent_id,
                'has_children' => $branch->hasVisibleChildren($actor, $page),
                'edit_url' => $this->resolveEditUrl($page),
                'type_icon' => $page->type?->admin['icon'] ?? null,
                'url' => $this->resolvePageUrl($page),
            ])
            ->values();

        return response()->json(['data' => $children]);
    }

    private function shouldUsePaginatedTree(Request $request): bool
    {
        return $request->hasAny(['mode', 'page', 'per_page']);
    }

    private function paginatedChildren(Request $request, Authenticatable $actor): JsonResponse
    {
        $parentId = $request->integer('parent') > 0 ? $request->integer('parent') : null;
        $siteId = $request->integer('site_id');
        $mode = $request->string('mode')->toString();

        if ($mode === '') {
            $mode = $parentId === null
                ? LoadHeaderNavigationChildrenAction::MODE_SITE_ROOT
                : LoadHeaderNavigationChildrenAction::MODE_PAGE_CHILDREN;
        }

        $branch = LoadHeaderNavigationChildrenAction::run(
            actor: $actor,
            mode: $mode,
            siteId: $siteId,
            parentId: $parentId,
            page: max(1, $request->integer('page', 1)),
            perPage: max(1, $request->integer('per_page', 10)),
        )->toRecord();

        /** @var list<array<string, mixed>> $items */
        $items = $branch['items'];

        $data = collect($items)
            ->map(fn (array $item): array => [
                'id' => $item['id'],
                'name' => $item['name'],
                'parent_id' => $item['parent_id'],
                'has_children' => $item['has_children'],
                'edit_url' => $item['edit_url'],
                'type_icon' => $item['type_icon'],
                'url' => $item['public_url'],
                'site_id' => $item['site_id'],
            ])
            ->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'page' => $branch['page'],
                'per_page' => $branch['per_page'],
                'has_more' => $branch['has_more'],
                'next_page' => $branch['next_page'],
            ],
        ]);
    }

    private function authorizeAdminPanelAccess(Request $request): Authenticatable
    {
        $actor = $request->user();

        abort_if(! $actor instanceof Authenticatable || ! $actor instanceof FilamentUser, 403);

        abort_unless(
            $actor->canAccessPanel(Filament::getPanel('admin'))
            && Gate::forUser($actor)->allows('viewAny', Page::class),
            403,
        );

        return $actor;
    }

    private function resolvePageUrl(Page $page): ?string
    {
        if (! $page->pageUrl->exists) {
            return null;
        }

        return PageUrlPresenter::fullUrl($page->pageUrl);
    }

    private function resolveEditUrl(Page $page): ?string
    {
        try {
            return route('filament.admin.resources.pages.edit', ['record' => $page->id]);
        } catch (Throwable) {
            return null;
        }
    }
}
