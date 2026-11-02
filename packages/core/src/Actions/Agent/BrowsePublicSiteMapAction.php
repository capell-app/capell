<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Agent;

use Capell\Core\Actions\PublicRead\PageUrlIsPubliclyReadableAction;
use Capell\Core\Actions\ResolvePublicPageableMorphTypesAction;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Support\PublicRead\PublicPageReadEligibilityRegistry;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * List anonymous-safe, URL-addressable pages for a site and language.
 *
 * Public-read eligibility is applied before pagination, so a restricted page
 * leaves no gap, count or link behind it.
 */
final class BrowsePublicSiteMapAction
{
    use AsFake;
    use AsObject;

    private const int PER_PAGE = 50;

    public function __construct(private readonly PublicPageReadEligibilityRegistry $eligibility) {}

    /** @return LengthAwarePaginator<int, array{url: string, title: string}> */
    public function handle(Site $site, Language $language, int $page = 1): LengthAwarePaginator
    {
        $morphTypes = ResolvePublicPageableMorphTypesAction::run();

        if ($morphTypes === []) {
            return new Paginator([], 0, self::PER_PAGE, $page);
        }

        $query = PageUrl::query()
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
            ->orderBy('url')
            ->orderBy('id');

        if (! $this->eligibility->hasContributors()) {
            return $query
                ->with(['translation'])
                ->paginate(self::PER_PAGE, ['*'], 'page', $page)
                ->through(static fn (PageUrl $pageUrl): array => self::project($pageUrl));
        }

        $readable = [];
        foreach ($query->with(['translation', 'siteDomain'])->lazy(200) as $pageUrl) {
            if (PageUrlIsPubliclyReadableAction::run($pageUrl)) {
                $readable[] = self::project($pageUrl);
            }
        }

        return new Paginator(
            array_slice($readable, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            count($readable),
            self::PER_PAGE,
            $page,
            ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'page'],
        );
    }

    /** @return array{url: string, title: string} */
    private static function project(PageUrl $pageUrl): array
    {
        return [
            'url' => $pageUrl->url,
            'title' => (string) $pageUrl->translation?->title,
        ];
    }
}
