<?php

declare(strict_types=1);

namespace Capell\Core\Observers;

use Capell\Core\Actions\SetupPageUrlsAction;
use Capell\Core\Concerns\RestoresSoftDeletedRelations;
use Capell\Core\Enums\CacheEnum;
use Capell\Core\Events\PageDeleted;
use Capell\Core\Events\PageSaved;
use Capell\Core\Exceptions\PageRestoreSlugConflictException;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Support\CapellCoreHelper;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PageObserver
{
    use RestoresSoftDeletedRelations;

    public function creating(Page $page): void
    {
        if ($page->getAttribute('blueprint_id') === null) {
            $page->blueprint_id = Blueprint::query()->pageType()->default()->value('id');
            throw_unless($page->blueprint_id !== null, InvalidArgumentException::class, 'Unable to create page without a type.');
        }

        if ($page->getAttribute('layout_id') === null) {
            $page->layout_id = Layout::query()->default()->value('id');
            throw_unless($page->layout_id !== null, InvalidArgumentException::class, 'Unable to create page without a layout.');
        }
    }

    public function updated(Page $page): void
    {
        if ($page->isDirty('parent_id')) {
            $this->updateUrl($page);
        }
    }

    public function deleted(Page $page): void
    {
        if ($page->isForceDeleting()) {
            $page->pageUrls()->withTrashed()->forceDelete();
            // Translations are MorphMany — force-delete any soft-deleted rows too.
            $page->translations()->withTrashed()->forceDelete();
        } else {
            $page->pageUrls()->delete();
            // Cascade soft-delete to translations so PageObserver::restored()
            // can bring them back through the shared soft-delete relation
            // restore guard.
            $page->translations()->delete();
        }

        $this->clearCache();
        event(new PageDeleted($page));
    }

    public function restoring(Page $page): void
    {
        $this->restoreTrashedAncestors($page);
        $this->assertNoSlugCollision($page);
    }

    public function restored(Page $page): void
    {
        DB::transaction(function () use ($page): void {
            // Restore everything that cascaded on the soft-delete. Previously
            // only pageUrls were restored, leaving widgets/sections and
            // translations orphaned. After this hook a restored page has the
            // same authoring surface it had before deletion.
            $page->pageUrls()->onlyTrashed()->restore();

            $this->restoreSoftDeletedRelations($page, [
                'translations',
                'widgets',
                'sections',
                'assetAttachments',
            ]);
        });

        $this->clearCache();
        event(new PageSaved($page));
    }

    public function saved(Page $page): void
    {
        $this->clearCache();
        event(new PageSaved($page));
    }

    private function restoreTrashedAncestors(Page $page): void
    {
        $parentId = $page->parent_id;

        while ($parentId !== null) {
            $parent = Page::query()
                ->withTrashed()
                ->find($parentId);

            if (! $parent instanceof Page) {
                return;
            }

            if ($parent->trashed()) {
                $parent->restore();
            }

            $parentId = $parent->parent_id;
        }
    }

    /**
     * Ensure no live (non-trashed) PageUrl now owns a URL this page used to
     * own. A restore that creates a routing duplicate would silently break
     * one or both pages on the public frontend.
     *
     * @throws PageRestoreSlugConflictException
     */
    private function assertNoSlugCollision(Page $page): void
    {
        $morph = $page->getMorphClass();
        $key = $page->getKey();

        $previousUrls = PageUrl::query()
            ->onlyTrashed()
            ->where('pageable_type', $morph)
            ->where('pageable_id', $key)
            ->pluck('url')
            ->filter()
            ->unique()
            ->values();

        if ($previousUrls->isEmpty()) {
            return;
        }

        $colliding = PageUrl::query()
            ->whereIn('url', $previousUrls)
            ->where(function ($q) use ($morph, $key): void {
                $q->where('pageable_type', '!=', $morph)
                    ->orWhere('pageable_id', '!=', $key);
            })
            ->pluck('pageable_id', 'url');

        if ($colliding->isNotEmpty()) {
            throw new PageRestoreSlugConflictException($page, $colliding->all());
        }
    }

    private function clearCache(): void
    {
        CapellCoreHelper::flushCache([
            CacheEnum::FirstPageByTypeForSite,
            CacheEnum::RelationExists,
        ]);
    }

    private function updateUrl(Page $page): void
    {
        SetupPageUrlsAction::run($page);
    }
}
