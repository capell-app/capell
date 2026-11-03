<?php

declare(strict_types=1);

namespace Capell\Frontend\Http\Controllers\Agent;

use Capell\Core\Actions\Agent\BrowsePublicSiteMapAction;
use Capell\Core\Models\Language;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\PublicRead\PublicReadCursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NavigationController
{
    public function __invoke(Request $request): JsonResponse
    {
        $domain = $request->attributes->get('capell.agent.domain');
        abort_unless($domain instanceof SiteDomain && $domain->language instanceof Language, 404);
        $input = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'cursor' => ['sometimes', 'string', 'max:200'],
        ]);
        $cursor = PublicReadCursor::decode(is_string($input['cursor'] ?? null) ? $input['cursor'] : null);
        abort_if(isset($input['cursor']) && ! $cursor instanceof PublicReadCursor, 422);
        $results = BrowsePublicSiteMapAction::run($domain->site, $domain->language, (int) ($input['page'] ?? 1), $cursor);

        return response()->json([
            'capellAgentSchema' => 1,
            'data' => $results->items(),
            'links' => ['next' => $results->nextPageUrl(), 'previous' => $results->previousPageUrl()],
        ]);
    }
}
