<?php

declare(strict_types=1);

namespace Capell\Installer\Support;

use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Site;
use Capell\Installer\Providers\InstallerServiceProvider;
use Throwable;

final class InstallerInstallationState
{
    public static function capellIsInstalled(): bool
    {
        $cacheKey = self::cacheKey();
        $memo = resolve(InstallerRuntimeMemo::class);

        if ($memo->has($cacheKey)) {
            return (bool) $memo->get($cacheKey);
        }

        try {
            $cached = CapellCore::getFromCache($cacheKey);
        } catch (Throwable) {
            // The installer must remain reachable when its configured cache
            // backend is not available during a fresh application boot.
            $cached = null;
        }

        if (is_bool($cached)) {
            $memo->put($cacheKey, $cached);

            return $cached;
        }

        $installed = self::probe();

        if ($installed === null) {
            // A missing table or unavailable connection is an accepted fresh
            // boot state. Report not installed without persisting an
            // indeterminate result that could survive database recovery.
            return false;
        }

        try {
            CapellCore::setToCache($cacheKey, $installed, ttl: 0);
        } catch (Throwable) {
            // The database result is authoritative even when the optional
            // persistent cache backend cannot accept the memoized value.
        }

        $memo->put($cacheKey, $installed);

        return $installed;
    }

    public static function capellIsNotInstalled(): bool
    {
        return ! self::capellIsInstalled();
    }

    public static function installerPackageIsInstalled(): bool
    {
        return CapellCore::getPackage(InstallerServiceProvider::$packageName)->isInstalled();
    }

    public static function forget(): void
    {
        self::resetRuntimeMemo();

        try {
            CapellCore::removeCacheKey(self::cacheKey());
        } catch (Throwable) {
            // Invalidating the process memo is still safe during early boot;
            // a later cache miss probes the database instead of throwing.
        }
    }

    /** @internal Used by invalidation listeners and tests. */
    public static function resetRuntimeMemo(): void
    {
        resolve(InstallerRuntimeMemo::class)->flush();
    }

    private static function probe(): ?bool
    {
        try {
            if (! CapellCore::getPackage(AdminServiceProvider::$packageName)->isInstalled()) {
                return false;
            }

            return Site::query()->exists();
        } catch (Throwable) {
            return null;
        }
    }

    private static function cacheKey(): string
    {
        $baseKey = trim((string) config(
            'capell-installer.installation_state_cache.key',
            'capell-installer.installation-state',
        ));
        $host = trim((string) config('capell-installer.installation_state_cache.host', ''));

        if ($host === '') {
            $configuredUrl = trim((string) config('app.url', ''));
            $parsedHost = parse_url($configuredUrl, PHP_URL_HOST);
            $normalizedHost = rtrim(strtolower(trim(
                is_string($parsedHost) && $parsedHost !== '' ? $parsedHost : $configuredUrl,
            )), '.');
            $host = substr(hash('xxh128', $normalizedHost), 0, 8);
        }

        $connection = (string) config('database.default', '');
        $database = (string) config("database.connections.{$connection}.database", '');

        return sprintf(
            '%s.%s.%s',
            $baseKey !== '' ? $baseKey : 'capell-installer.installation-state',
            $host,
            substr(hash('xxh128', "{$connection}:{$database}"), 0, 8),
        );
    }
}
