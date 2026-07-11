<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Models\Site;
use Capell\Frontend\Contracts\StaticErrorPageStore;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * Regenerate the static error pages for a single site.
 *
 * Mirrors the html-cache targeted-invalidation flow: dispatched after the
 * response (or synchronously in console/tests) when an error-page-relevant
 * model changes. Never throws — failures are logged and swallowed so a model
 * save is never blocked by error-page regeneration.
 *
 * @method static void run(int $siteId)
 */
class RegenerateSiteErrorPagesAction
{
    use AsFake;
    use AsJob;
    use AsObject;

    public function handle(int $siteId): void
    {
        if (! app()->bound(StaticErrorPageStore::class)) {
            return;
        }

        $site = Site::query()
            ->whereKey($siteId)
            ->with(['language', 'siteDomains.language', 'theme', 'translations', 'logo'])
            ->first();

        if (! $site instanceof Site || ! $site->isEnabled()) {
            return;
        }

        try {
            GenerateErrorPageCacheAction::run($site);
        } catch (Throwable $throwable) {
            Log::warning('capell: error page regeneration failed', [
                'site_id' => $siteId,
                'exception' => $throwable->getMessage(),
            ]);
        }
    }
}
