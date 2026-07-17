<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Models\PageUrl;
use Capell\Core\Models\SiteDomain;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static int run()
 */
class RepairPageUrlsMissingSiteDomainsAction
{
    use AsFake;
    use AsObject;

    public function handle(): int
    {
        $pageUrls = FindPageUrlsMissingSiteDomainsAction::run();
        $repaired = 0;

        $pageUrls
            ->map(fn (PageUrl $pageUrl): string => sprintf('%d:%d', $pageUrl->site_id, $pageUrl->language_id))
            ->unique()
            ->each(function (string $key) use (&$repaired): void {
                [$siteId, $languageId] = array_map(intval(...), explode(':', $key, 2));

                $siteDomain = SiteDomain::query()
                    ->withTrashed()
                    ->where('site_id', $siteId)
                    ->where('language_id', $languageId)
                    ->first();

                if ($siteDomain instanceof SiteDomain) {
                    $siteDomain->restore();
                    $siteDomain->forceFill(['status' => true])->save();
                    $repaired++;

                    return;
                }

                SiteDomain::query()->create([
                    'site_id' => $siteId,
                    'language_id' => $languageId,
                    'domain' => null,
                    'scheme' => null,
                    'path' => null,
                    'default' => ! SiteDomain::query()->where('site_id', $siteId)->exists(),
                    'status' => true,
                ]);

                $repaired++;
            });

        return $repaired;
    }
}
