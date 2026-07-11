<?php

declare(strict_types=1);

namespace Capell\Admin\Http\Controllers;

use Capell\Admin\Actions\Pages\BuildFrontendResourceDebugOverlayPayloadAction;
use Capell\Core\Actions\LoadSiteDomainFromUrlAction;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\SiteDomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class FrontendResourceDebugOverlayController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $page = $this->page($request);

        abort_unless($page instanceof Page, 404);

        Gate::authorize('view', $page);

        return response()->json(BuildFrontendResourceDebugOverlayPayloadAction::run($page));
    }

    private function page(Request $request): ?Page
    {
        $pageId = $request->integer('page_id');

        if ($pageId > 0) {
            return Page::query()->find($pageId);
        }

        $url = $request->string('url')->trim()->toString();

        if ($url === '') {
            return null;
        }

        $resolvedDomain = LoadSiteDomainFromUrlAction::run($url);

        if (! is_array($resolvedDomain)) {
            return null;
        }

        [$siteDomain, $path] = $resolvedDomain;

        if (! $siteDomain instanceof SiteDomain || ! is_string($path)) {
            return null;
        }

        $pageUrl = PageUrl::loadByUrl($path, $siteDomain);

        $pageUrl?->loadMissing('pageable');

        return $pageUrl?->pageable instanceof Page ? $pageUrl->pageable : null;
    }
}
