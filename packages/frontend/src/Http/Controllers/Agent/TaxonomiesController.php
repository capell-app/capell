<?php

declare(strict_types=1);

namespace Capell\Frontend\Http\Controllers\Agent;

use Capell\Core\Actions\Properties\BrowseAgentTaxonomiesAction;
use Capell\Core\Models\SiteDomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TaxonomiesController
{
    public function __invoke(Request $request, ?string $key = null): JsonResponse
    {
        $domain = $request->attributes->get('capell.agent.domain');
        abort_unless($domain instanceof SiteDomain, 404);
        $input = $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:1000'], 'key' => ['sometimes', 'string', 'max:100', 'regex:/\A[A-Za-z0-9_.-]+\z/']]);
        $results = BrowseAgentTaxonomiesAction::run($domain->site, $key ?? ($input['key'] ?? null), (int) ($input['page'] ?? 1));

        return response()->json([
            'capellAgentSchema' => 1,
            'data' => $results->items(),
            'links' => ['next' => $results->appends($request->only('key'))->nextPageUrl(), 'previous' => $results->previousPageUrl()],
        ]);
    }
}
