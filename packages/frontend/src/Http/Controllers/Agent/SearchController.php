<?php

declare(strict_types=1);

namespace Capell\Frontend\Http\Controllers\Agent;

use Capell\Core\Contracts\Agent\AgentPageSearch;
use Capell\Core\Data\Agent\AgentSearchResultData;
use Capell\Core\Data\PublicRead\PublicPageReadCandidateData;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\PublicRead\PublicPageReadEligibilityRegistry;
use Capell\Core\Support\PublicRead\PublicReadCursor;
use Capell\Core\Support\PublicRead\PublicReadPaginator;
use Capell\Core\Support\PublicRead\PublicReadScanner;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;

final class SearchController
{
    public function __invoke(Request $request): JsonResponse
    {
        $domain = $request->attributes->get('capell.agent.domain');
        abort_unless($domain instanceof SiteDomain, 404);
        $input = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'cursor' => ['sometimes', 'string', 'max:200'],
        ]);
        abort_unless(app()->bound(AgentPageSearch::class), 503);
        $cursor = PublicReadCursor::decode(is_string($input['cursor'] ?? null) ? $input['cursor'] : null);
        abort_if(isset($input['cursor']) && ! $cursor instanceof PublicReadCursor, 422);

        $search = resolve(AgentPageSearch::class);
        $query = (string) $input['q'];
        $page = (int) ($input['page'] ?? 1);
        /** @var array<int, LengthAwarePaginator<int, AgentSearchResultData>> $providerPages */
        $providerPages = [];
        $providerPage = static function (int $number) use (&$providerPages, $search, $query, $domain): LengthAwarePaginator {
            return $providerPages[$number] ??= $search->search($query, $domain->site_id, $domain->language_id, $number);
        };
        $perPage = max(1, $providerPage(1)->perPage());

        // The provider's own pages and totals describe unfiltered results, so
        // this boundary pages through them itself and builds its own links
        // from the readable results only.
        $result = resolve(PublicReadScanner::class)->scan(
            fetch: static function (int $offset, int $limit) use ($providerPage, $perPage): array {
                // Positions are provider page and index within what that page
                // returned, so a short provider page never shifts a cursor.
                $number = intdiv($offset, $perPage) + 1;
                $results = $providerPage($number);
                $items = array_values(array_filter($results->items(), static fn (mixed $result): bool => $result instanceof AgentSearchResultData));
                $start = $offset % $perPage;
                $slice = array_slice($items, $start, $limit);

                if ($start + count($slice) < count($items)) {
                    return ['items' => $slice, 'more' => true];
                }

                return ['items' => $slice, 'more' => $results->hasMorePages(), 'next' => $number * $perPage, 'scanned' => max(1, $perPage - $start)];
            },
            readable: static fn (array $results): array => resolve(PublicPageReadEligibilityRegistry::class)->allowsMany(array_map(
                static fn (AgentSearchResultData $result): PublicPageReadCandidateData => new PublicPageReadCandidateData(
                    url: $result->url,
                    siteId: $domain->site_id,
                    languageId: $domain->language_id,
                    publishedOn: $domain->full_url,
                ),
                $results,
            )),
            size: $perPage,
            page: $page,
            cursor: $cursor,
        );
        $links = new PublicReadPaginator(
            items: $result->items,
            perPage: $perPage,
            currentPage: $page,
            options: ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'page'],
            nextCursor: $result->next,
            cursorName: 'cursor',
            fromCursor: $cursor instanceof PublicReadCursor,
        )->appends(['q' => $query]);
        $readable = $result->items;

        return response()->json([
            'capellAgentSchema' => 1,
            'data' => array_map(static fn (AgentSearchResultData $result): array => [
                'url' => $result->url, 'title' => $result->title, 'snippet' => $result->snippet,
            ], $readable),
            'links' => ['next' => $links->nextPageUrl(), 'previous' => $links->previousPageUrl()],
        ]);
    }
}
