<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Enums\CacheEnum;
use Capell\Core\Events\FrontendSurrogateKeysInvalidated;
use Capell\Core\Events\PageSaved;
use Capell\Core\Models\DeletionBatch;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\CapellCoreHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static bool run(Site $site)
 */
final class RestoreSiteAction
{
    use AsObject;

    public function handle(Site $site): bool
    {
        return DB::transaction(function () use ($site): bool {
            $batch = DeletionBatch::query()
                ->with('records')
                ->where('root_type', Site::class)
                ->where('root_id', $site->getKey())
                ->open()
                ->latest()
                ->first();

            if (! $batch instanceof DeletionBatch) {
                return (bool) $site->restore();
            }

            $this->restoreModels(Site::class, collect([$site->getKey()]));
            $this->restoreBatchRecords($batch, Layout::class);
            $this->restoreBatchRecords($batch, Page::class);
            $this->restoreBatchRecords($batch, SiteDomain::class);
            $this->restoreBatchRecords($batch, PageUrl::class);
            $this->flushRestoredBatchSideEffects($batch, $site);

            $batch->update(['restored_at' => now()]);

            return true;
        });
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
            Page::class => Page::withTrashed()->whereKey($modelIds->all())->restore(),
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
