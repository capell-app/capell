<?php

declare(strict_types=1);

namespace Capell\Frontend\Http\Controllers\Agent;

use Capell\Core\Actions\Agent\BuildAgentPageReadDataAction;
use Capell\Core\Actions\Properties\QueryPagesByPropertiesAction;
use Capell\Core\Models\Page;
use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Http\Requests\Agent\PageQueryRequest;
use Illuminate\Http\JsonResponse;

final class PagesController
{
    public function __invoke(PageQueryRequest $request): JsonResponse
    {
        $domain = $request->attributes->get('capell.agent.domain');
        abort_unless($domain instanceof SiteDomain, 404);
        $pages = QueryPagesByPropertiesAction::run($domain->site, $request->queryData($domain->language_id));
        $data = [];
        foreach ($pages->items() as $page) {
            if (! $page instanceof Page) {
                continue;
            }

            $result = BuildAgentPageReadDataAction::run($page, $domain->language);
            if ($result !== null) {
                $data[] = $result->toArray();
            }
        }

        return response()->json([
            'capellAgentSchema' => 1,
            'data' => $data,
            'links' => [
                'next' => $pages->appends($request->validated())->nextPageUrl(),
                'previous' => $pages->previousPageUrl(),
            ],
        ]);
    }
}
