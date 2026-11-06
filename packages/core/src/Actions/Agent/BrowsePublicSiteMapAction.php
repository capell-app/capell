<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Agent;

use Capell\Core\Actions\PublicRead\PageUrlsArePubliclyReadableAction;
use Capell\Core\Actions\ResolvePublicPageableMorphTypesAction;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Support\PublicRead\PublicPageReadEligibilityRegistry;
use Capell\Core\Support\PublicRead\PublicReadCursor;
use Capell\Core\Support\PublicRead\PublicReadPaginator;
use Capell\Core\Support\PublicRead\PublicReadScanner;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Paginator;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * List anonymous-safe, URL-addressable pages for a site and language.
 *
 * Public-read eligibility is applied before pagination, so a restricted page
 * leaves no gap or link behind it. Only next and previous links are offered:
 * no total is computed, a request reads at most PublicReadScanner::MAX_SCANNED
 * addresses, and a next link carries a cursor that continues the read.
 *
 * @method static Paginator<int, array{url: string, title: string}> run(Site $site, Language $language, int $page = 1, ?string $cursorToken = null)
 */
final class BrowsePublicSiteMapAction
{
    use AsFake;
    use AsObject;

    private const int PER_PAGE = 50;

    public function __construct(private readonly PublicPageReadEligibilityRegistry $eligibility) {}

    /**
     * @param  string|null  $cursorToken  a sealed cursor from a previous next link; one that does not open for this read starts again at the first page
     * @return Paginator<int, array{url: string, title: string}>
     */
    public function handle(Site $site, Language $language, int $page = 1, ?string $cursorToken = null): Paginator
    {
        $page = max(1, $page);
        $morphTypes = ResolvePublicPageableMorphTypesAction::run();

        if ($morphTypes === []) {
            return $this->paginator([], $page);
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
            return $this->paginator(
                array_values($query->with(['translation'])->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE + 1)->get()->map(static fn (PageUrl $pageUrl): array => self::project($pageUrl))->all()),
                $page,
            );
        }

        // Read in bounded batches only until this page and one more readable
        // entry are known. A cursor continues after the last address read, by
        // its (url, id) order, so a change between requests never shifts it.
        $context = PublicReadCursor::context('agent.navigation', [(int) $site->getKey(), (int) $language->getKey()]);
        $cursor = PublicReadCursor::open($cursorToken, $context);
        $page = $cursor instanceof PublicReadCursor ? $page : ($cursorToken === null ? $page : 1);
        $table = (new PageUrl)->getTable();
        $result = resolve(PublicReadScanner::class)->scan(
            fetch: static function (?array $after, int $limit) use ($query, $table): array {
                $batch = $query->clone()->with(['translation', 'siteDomain']);

                if ($after !== null) {
                    $url = (string) ($after['u'] ?? '');
                    $id = (int) ($after['i'] ?? 0);
                    $batch->where(static fn (Builder $keyset): Builder => $keyset
                        ->where($table . '.url', '>', $url)
                        ->orWhere(static fn (Builder $same): Builder => $same->where($table . '.url', '=', $url)->where($table . '.id', '>', $id)));
                }

                $pageUrls = array_values($batch->limit($limit)->get()->all());
                $positions = array_map(static fn (PageUrl $pageUrl): array => ['u' => (string) $pageUrl->url, 'i' => (int) $pageUrl->getKey()], $pageUrls);

                return ['items' => $pageUrls, 'positions' => $positions, 'after' => $positions === [] ? $after : $positions[array_key_last($positions)], 'more' => count($pageUrls) === $limit];
            },
            readable: static fn (array $pageUrls): array => PageUrlsArePubliclyReadableAction::run($pageUrls),
            size: self::PER_PAGE,
            page: $page,
            cursor: $cursor,
        );

        return new PublicReadPaginator(
            items: array_map(self::project(...), $result->items),
            perPage: self::PER_PAGE,
            currentPage: $page,
            options: ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'page'],
            nextToken: $result->next?->seal($context),
            cursorName: 'cursor',
            fromCursor: $cursor instanceof PublicReadCursor,
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

    /**
     * @param  list<array{url: string, title: string}>  $items
     * @return Paginator<int, array{url: string, title: string}>
     */
    private function paginator(array $items, int $page): Paginator
    {
        return new Paginator($items, self::PER_PAGE, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]);
    }
}
