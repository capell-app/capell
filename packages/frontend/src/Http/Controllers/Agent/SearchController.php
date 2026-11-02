<?php

declare(strict_types=1);

namespace Capell\Frontend\Http\Controllers\Agent;

use Capell\Core\Contracts\Agent\AgentPageSearch;
use Capell\Core\Data\Agent\AgentSearchResultData;
use Capell\Core\Data\PublicRead\PublicPageReadCandidateData;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\PublicRead\PublicPageReadEligibilityRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SearchController
{
    public function __invoke(Request $request): JsonResponse
    {
        $domain = $request->attributes->get('capell.agent.domain');
        abort_unless($domain instanceof SiteDomain, 404);
        $input = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);
        abort_unless(app()->bound(AgentPageSearch::class), 503);
        $results = resolve(AgentPageSearch::class)->search($input['q'], $domain->site_id, $domain->language_id, (int) ($input['page'] ?? 1));
        $eligibility = resolve(PublicPageReadEligibilityRegistry::class);
        // Search implementations own pagination and should omit restricted pages
        // themselves; this is the boundary's own guarantee for any implementation.
        $readable = array_values(array_filter(
            $results->items(),
            static fn (mixed $result): bool => $result instanceof AgentSearchResultData && $eligibility->allows(new PublicPageReadCandidateData(
                url: $result->url,
                siteId: $domain->site_id,
                languageId: $domain->language_id,
            )),
        ));

        return response()->json([
            'capellAgentSchema' => 1,
            'data' => array_map(static fn (AgentSearchResultData $result): array => [
                'url' => $result->url, 'title' => $result->title, 'snippet' => $result->snippet,
            ], $readable),
            'links' => ['next' => $results->appends(['q' => $input['q']])->nextPageUrl(), 'previous' => $results->previousPageUrl()],
        ]);
    }
}
