<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Models\PageUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class FindPageUrlRestorationConflictAction
{
    use AsFake;
    use AsObject;

    /** @param Collection<int, PageUrl> $urlsToRestore */
    public function handle(Collection $urlsToRestore): ?PageUrl
    {
        $urlsToRestore = $urlsToRestore->filter(fn (PageUrl $candidate): bool => $candidate->status);
        $url = $urlsToRestore->first();
        if (! $url instanceof PageUrl) {
            return null;
        }

        $urls = $urlsToRestore->pluck('url')->unique()->all();
        // Let SQL compare route keys using the same collation as normal routing.
        $conflict = PageUrl::on($url->getConnectionName())->withTrashed()
            ->whereIn('page_urls.url', $urls)->where('page_urls.status', true)
            ->where(fn (Builder $query): Builder => $query->whereNull('page_urls.deleted_at')->orWhereIn('page_urls.id', $urlsToRestore->modelKeys()))
            ->join('page_urls as restoring_urls', function (JoinClause $join): void {
                $join->on('page_urls.site_id', '=', 'restoring_urls.site_id')
                    ->on('page_urls.language_id', '=', 'restoring_urls.language_id')
                    ->on('page_urls.url', '=', 'restoring_urls.url')
                    ->on('page_urls.id', '!=', 'restoring_urls.id');
            })
            ->whereIn('restoring_urls.id', $urlsToRestore->modelKeys())
            ->select(['restoring_urls.*', 'page_urls.pageable_id as conflicting_page_id'])
            ->lockForUpdate()->first();

        return $conflict;
    }
}
