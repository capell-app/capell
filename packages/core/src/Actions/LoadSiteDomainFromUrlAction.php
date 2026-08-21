<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Actions\SiteDomains\ResolveSiteDomainAction;
use Capell\Core\Data\SiteDomains\SiteRequestTargetData;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @deprecated Use ResolveSiteDomainAction with SiteRequestTargetData.
 *
 * @method static array{0: SiteDomain, 1: string}|null run(string $url, ?Collection<int, Site> $sites = null)
 */
final class LoadSiteDomainFromUrlAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  Collection<int, Site>|null  $sites
     * @return array{0: SiteDomain, 1: string}|null
     */
    public function handle(string $url, ?Collection $sites = null): ?array
    {
        try {
            $target = SiteRequestTargetData::fromUrl($url);
        } catch (InvalidArgumentException) {
            return null;
        }

        $sites ??= Site::query()->excludingPreview()->with('siteDomains')->get();
        $resolution = ResolveSiteDomainAction::run($target, $sites);

        if ($resolution === null) {
            return null;
        }

        return [$resolution->siteDomainForRequest(), $resolution->relativePath];
    }
}
