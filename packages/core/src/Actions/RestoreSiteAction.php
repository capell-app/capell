<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Enums\CacheEnum;
use Capell\Core\Events\FrontendSurrogateKeysInvalidated;
use Capell\Core\Events\PageSaved;
use Capell\Core\Exceptions\PageRestoreCancelledException;
use Capell\Core\Exceptions\PageUrlCollisionException;
use Capell\Core\Models\DeletionBatch;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\CapellCoreHelper;
use Capell\Core\Support\PageRestoreLifecycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static bool run(Site $site)
 */
final class RestoreSiteAction
{
    use AsFake;
    use AsObject;

    public function handle(Site $site): bool
    {
        try {
            return $site->getConnection()->transaction(function () use ($site): bool {
                $current = $site->newQuery()->onlyTrashed()->whereKey($site->getKey())->lockForUpdate()->first();
                if (! $current instanceof Site) {
                    return false;
                }

                $site = $current;
                $batch = DeletionBatch::on($site->getConnectionName())
                    ->with('records')
                    ->where('root_type', Site::class)
                    ->where('root_id', $site->getKey())
                    ->open()
                    ->latest()
                    ->lockForUpdate()->first();

                if (! $batch instanceof DeletionBatch) {
                    return (bool) $site->restore();
                }

                $ids = $batch->records->where('model_type', Page::class)->pluck('model_id')->map(intval(...))->all();
                $pages = Page::on($site->getConnectionName())->onlyTrashed()->whereKey($ids)->orderBy('_lft')->lockForUpdate()->get();
                foreach ($pages as $page) {
                    throw_unless(resolve(PageRestoreLifecycle::class)->supports($page), PageRestoreCancelledException::class);
                    $cascade = CollectPageRestoreCascadeIdsAction::run($page, lockForUpdate: true);
                    throw_if(array_diff($cascade, $ids) !== [], PageRestoreCancelledException::class);
                }

                throw_unless(CanRestorePageMembersAction::run($pages), PageRestoreCancelledException::class);
                AssertPageRestoreUrlsAvailableAction::run($pages);
                $urlIds = $batch->records->where('model_type', PageUrl::class)->pluck('model_id')->all();
                $urls = PageUrl::on($site->getConnectionName())->onlyTrashed()->whereKey($urlIds)->lockForUpdate()->get();
                $conflict = FindPageUrlRestorationConflictAction::run($urls);
                if ($conflict instanceof PageUrl) {
                    throw new PageUrlCollisionException($conflict->url, $conflict->site_id, $conflict->language_id);
                }

                $this->restoreModels(Site::class, collect([$site->getKey()]));
                $this->restoreBatchRecords($batch, Layout::class);
                resolve(PageRestoreLifecycle::class)->preservingSiteHistory(static function () use ($pages): void {
                    foreach ($pages as $page) {
                        if ($page->fresh()?->trashed() === true) {
                            throw_unless($page->restore(), PageRestoreCancelledException::class);
                        }
                    }
                });

                $this->restoreBatchRecords($batch, SiteDomain::class);
                $this->restoreBatchRecords($batch, PageUrl::class);
                resolve(PageRestoreLifecycle::class)->afterCommit($site->getConnection(), fn () => $this->flushRestoredBatchSideEffects($batch, $site));

                $batch->newQuery()->whereKey($batch->getKey())->update(['restored_at' => now()]);

                return true;
            });
        } catch (PageRestoreCancelledException) {
            return false;
        }
    }

    private function restoreBatchRecords(DeletionBatch $batch, string $modelType): void
    {
        if (! is_subclass_of($modelType, Model::class)) {
            return;
        }

        $modelIds = $batch->records
            ->where('model_type', $modelType)
            ->pluck('model_id')
            ->unique()
            ->values();

        /** @var class-string<Model> $modelType */
        $this->restoreModels($modelType, $modelIds);
    }

    /**
     * @param  class-string<Model>  $modelType
     * @param  Collection<int, int|string>  $modelIds
     */
    private function restoreModels(string $modelType, Collection $modelIds): void
    {
        if ($modelIds->isEmpty()) {
            return;
        }

        match ($modelType) {
            Layout::class => Layout::withTrashed()->whereKey($modelIds->all())->restore(),
            PageUrl::class => PageUrl::withTrashed()->whereKey($modelIds->all())->restore(),
            Site::class => Site::withTrashed()->whereKey($modelIds->all())->restore(),
            SiteDomain::class => SiteDomain::withTrashed()->whereKey($modelIds->all())->restore(),
            default => null,
        };
    }

    private function flushRestoredBatchSideEffects(DeletionBatch $batch, Site $site): void
    {
        CapellCoreHelper::flushCache([
            CacheEnum::FirstPageByTypeForSite,
            CacheEnum::RelationExists,
            CacheEnum::Site,
            CacheEnum::SiteLanguages,
            CacheEnum::LanguageByIdOrSite,
        ]);

        event(new FrontendSurrogateKeysInvalidated(['site-' . $site->getKey()]));

        $pageIds = $batch->records
            ->where('model_type', Page::class)
            ->pluck('model_id')
            ->unique()
            ->values();

        if ($pageIds->isEmpty()) {
            return;
        }

        Page::query()
            ->whereKey($pageIds->all())
            ->get()
            ->each(function (Page $page): void {
                event(new PageSaved($page));
            });
    }
}
