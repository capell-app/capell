<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Agent;

use Capell\Core\Actions\ResolvePublicPageableMorphTypesAction;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** List anonymous-safe, URL-addressable pages for a site and language. */
final class BrowsePublicSiteMapAction
{
    use AsFake;
    use AsObject;

    /** @return LengthAwarePaginator<int, array{url: string, title: string}> */
    public function handle(Site $site, Language $language, int $page = 1): LengthAwarePaginator
    {
        $morphTypes = ResolvePublicPageableMorphTypesAction::run();

        if ($morphTypes === []) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, 50, $page);
        }

        return PageUrl::query()
            ->where('site_id', $site->getKey())
            ->where('language_id', $language->getKey())
            ->enabled()
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('type')
                    ->orWhere('type', '!=', UrlTypeEnum::Redirect);
            })
            ->whereHas('siteDomain', static fn (Builder $domain): Builder => $domain->where('status', true))
            ->whereHas('translation', fn (Builder $query): Builder => $query->where('language_id', $language->getKey()))
            ->whereIn('pageable_type', $morphTypes)
            ->whereHasMorph(
                'pageable',
                $morphTypes,
                fn (BuilderContract $query): BuilderContract => $query
                    ->where('site_id', $site->getKey())
                    ->whereHas('blueprint', static fn (BuilderContract $blueprint): BuilderContract => $blueprint->enabled()->accessible())
                    ->publishedDate(),
            )
            ->with(['translation'])
            ->orderBy('url')
            ->paginate(50, ['*'], 'page', $page)
            ->through(static fn (PageUrl $pageUrl): array => [
                'url' => $pageUrl->url,
                'title' => (string) $pageUrl->translation?->title,
            ]);
    }
}
