<?php

declare(strict_types=1);

namespace Capell\Core\Actions\SiteDomains;

use Capell\Core\Enums\CacheEnum;
use Capell\Core\Models\Site;
use Capell\Core\Support\Cache\CapellCacheManager;
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
     * Changes on every forget. A request whose query read rows before a change committed
     * sees the revision move and drops what it cached, so the old list cannot outlive the
     * commit-time forget. Only a cache miss reads it; a hit costs nothing extra.
     */
    private const string REVISION_KEY = 'site-routing-candidates-revision';

    /**
     * Forget the candidates now, so this request reads the change, and again when the
     * surrounding transaction commits or rolls back, so no process keeps rows cached
     * while the transaction was open.
     */
    public static function forgetAfterCommit(Connection $connection): void
    {
        self::forget();

        $connection->afterCommit(static function (): void {
            self::forget();
        });

        // A resolution inside the transaction may have cached rows that a rollback discards.
        $connection->afterRollBack(static function (): void {
            self::forget();
        });
    }

    public static function forget(): void
    {
        // Move the revision first: a request that cached between the two steps then
        // either sees the move and drops its entry, or has its entry removed here.
        $cache = resolve(CapellCacheManager::class);
        $cache->setToCache(self::REVISION_KEY, self::newRevision(), 0);
        $cache->removeCacheKey(CacheEnum::SiteRoutingCandidates->value);
    }

    /** @return Collection<int, Site> */
    public function handle(): Collection
    {
        if (config('capell.disable_cache') === true) {
            return $this->query();
        }

        $cache = resolve(CapellCacheManager::class);
        $key = CacheEnum::SiteRoutingCandidates->value;

        // Read the shared store every time: a long-lived queue worker's per-process copy is
        // never reached by another process's forget, so it could keep routing a removed host.
        $sites = $cache->getFreshFromCache($key);

        if ($sites instanceof Collection) {
            /** @var Collection<int, Site> $sites */
            return $sites;
        }

        $revision = $cache->getFreshFromCache(self::REVISION_KEY);

        if (! is_string($revision)) {
            // Seed an absent revision (flushed or evicted), so a change that then moves it
            // and is flushed again cannot read back as the same empty value.
            $revision = self::newRevision();
            $cache->setToCache(self::REVISION_KEY, $revision, 0);
        }

        $sites = $this->query();
        $cache->setToCache($key, $sites, self::TTL_SECONDS);

        if ($cache->getFreshFromCache(self::REVISION_KEY) !== $revision) {
            // A change committed while the query ran, or the revision was lost: this request
            // still uses the rows it read, but no other request may reuse them.
            $cache->removeCacheKey($key);
        }

        return $sites;
    }

    private static function newRevision(): string
    {
        return bin2hex(random_bytes(8));
    }

    /** @return Collection<int, Site> */
    private function query(): Collection
    {
        return Site::query()->excludingPreview()->with('siteDomains')->get();
    }
}
