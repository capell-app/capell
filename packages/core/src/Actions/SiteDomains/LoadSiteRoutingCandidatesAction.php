<?php

declare(strict_types=1);

namespace Capell\Core\Actions\SiteDomains;

use Capell\Core\Enums\CacheEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Site;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * The public sites and their domains that a request host can resolve to, read once
 * from the shared cache instead of loading every site on every request.
 *
 * Every domain is kept whatever its status: ResolveSiteDomainAction needs disabled
 * exact-path domains to refuse a request rather than fall through to another site.
 * Site and site-domain observers forget the entry; the one-hour ceiling bounds how
 * long a bulk update that skips model events can leave routing stale.
 *
 * @method static Collection<int, Site> run()
 */
final class LoadSiteRoutingCandidatesAction
{
    use AsFake;
    use AsObject;

    public const int TTL_SECONDS = 3600;

    /**
     * Forget the candidates now, so this request reads the change, and again after the
     * surrounding transaction commits, so a concurrent request cannot keep the
     * pre-commit rows it cached while the transaction was open.
     */
    public static function forgetAfterCommit(Connection $connection): void
    {
        self::forget();

        $connection->afterCommit(static function (): void {
            self::forget();
        });
    }

    public static function forget(): void
    {
        CapellCore::removeCacheKey(CacheEnum::SiteRoutingCandidates->value);
    }

    /** @return Collection<int, Site> */
    public function handle(): Collection
    {
        $sites = CapellCore::rememberCache(
            CacheEnum::SiteRoutingCandidates,
            static fn (): Collection => Site::query()->excludingPreview()->with('siteDomains')->get(),
            self::TTL_SECONDS,
        );

        if ($sites instanceof Collection) {
            /** @var Collection<int, Site> $sites */
            return $sites;
        }

        // An unreadable cached value must never decide routing.
        self::forget();

        return Site::query()->excludingPreview()->with('siteDomains')->get();
    }
}
