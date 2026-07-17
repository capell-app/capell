<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Models\DeletionBatch;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static bool run(Site $site)
 */
final class DeleteSiteAction
{
    use AsFake;
    use AsObject;

    public function handle(Site $site): bool
    {
        if ($site->trashed()) {
            return true;
        }

        return DB::transaction(function () use ($site): bool {
            $batch = DeletionBatch::query()->create([
                'root_type' => Site::class,
                'root_id' => $site->getKey(),
            ]);

            $this->recordModel($batch, $site);

            $pageIds = Page::query()
                ->whereBelongsTo($site)
                ->pluck((new Page)->getKeyName());

            $this->recordModelIds($batch, Page::class, $pageIds);
            $this->recordModelIds($batch, PageUrl::class, $this->pageUrlIds($site));
            $this->recordModelIds($batch, SiteDomain::class, $this->siteDomainIds($site));
            $this->recordModelIds($batch, Layout::class, $this->layoutIds($site));

            $this->deletePageUrls($site);
            $this->deleteSiteDomains($site);
            $this->deleteLayouts($site);
            $this->deletePages($site);

            return (bool) $site->delete();
        });
    }

    private function recordModel(DeletionBatch $batch, Model $model): void
    {
        $this->recordModelIds($batch, $model::class, collect([$model->getKey()]));
    }

    /**
     * @param  Collection<int, int|string>  $modelIds
     */
    private function recordModelIds(DeletionBatch $batch, string $modelType, Collection $modelIds): void
    {
        $records = $modelIds
            ->unique()
            ->values()
            ->map(fn (int|string $modelId): array => [
                'deletion_batch_id' => $batch->getKey(),
                'model_type' => $modelType,
                'model_id' => (int) $modelId,
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->all();

        if ($records === []) {
            return;
        }

        $batch->records()->insert($records);
    }

    /** @return Collection<int, int|string> */
    private function pageUrlIds(Site $site): Collection
    {
        return PageUrl::query()
            ->whereBelongsTo($site)
            ->pluck((new PageUrl)->getKeyName());
    }

    /** @return Collection<int, int|string> */
    private function siteDomainIds(Site $site): Collection
    {
        return SiteDomain::query()
            ->whereBelongsTo($site)
            ->pluck((new SiteDomain)->getKeyName());
    }

    /** @return Collection<int, int|string> */
    private function layoutIds(Site $site): Collection
    {
        return Layout::query()
            ->whereBelongsTo($site)
            ->pluck((new Layout)->getKeyName());
    }

    private function deletePageUrls(Site $site): void
    {
        PageUrl::query()
            ->whereBelongsTo($site)
            ->get()
            ->each(fn (PageUrl $pageUrl): ?bool => $pageUrl->delete());
    }

    private function deleteSiteDomains(Site $site): void
    {
        $site->siteDomains()
            ->get()
            ->each(fn (SiteDomain $siteDomain): ?bool => $siteDomain->delete());
    }

    private function deleteLayouts(Site $site): void
    {
        $site->layouts()
            ->get()
            ->each(fn (Layout $layout): ?bool => $layout->delete());
    }

    private function deletePages(Site $site): void
    {
        /** @var EloquentCollection<int, Page> $pages */
        $pages = Page::query()
            ->whereBelongsTo($site)
            ->orderByDesc('_lft')
            ->get();

        $pages->each(fn (Page $page): ?bool => $page->delete());
    }
}
