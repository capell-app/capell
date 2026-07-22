<?php

declare(strict_types=1);

namespace Capell\Installer\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class InstallerDatabaseTableState
{
    /** @return list<string> */
    public static function availableTables(): array
    {
        $cacheKey = self::cacheKey();
        $memo = resolve(InstallerRuntimeMemo::class);

        if ($memo->has($cacheKey)) {
            /** @var list<string> */
            return $memo->get($cacheKey);
        }

        try {
            $cache = Cache::store((string) config('capell-installer.database_table_cache.store', 'file'));
            $cached = $cache->get($cacheKey);

            if (is_array($cached) && array_is_list($cached)) {
                $tables = array_values(array_filter($cached, 'is_string'));
                $memo->put($cacheKey, $tables);

                return $tables;
            }

            $tables = Schema::getTableListing(schemaQualified: false);
            $cache->forever($cacheKey, $tables);

            $memo->put($cacheKey, $tables);

            return $tables;
        } catch (Throwable) {
            // A missing connection is an accepted pre-install state. Do not
            // cache it: the next request must see a recovered database.
            return [];
        }
    }

    public static function forget(): void
    {
        $cacheKey = self::cacheKey();
        self::resetRuntimeMemo();

        try {
            Cache::store((string) config('capell-installer.database_table_cache.store', 'file'))
                ->forget($cacheKey);
        } catch (Throwable) {
            // Early boot may not have a writable cache store. The process memo
            // is still invalidated and an uncached probe remains safe.
        }
    }

    /** @internal Used by invalidation listeners and tests. */
    public static function resetRuntimeMemo(): void
    {
        resolve(InstallerRuntimeMemo::class)->flush();
    }

    private static function cacheKey(): string
    {
        $baseKey = trim((string) config(
            'capell-installer.database_table_cache.key',
            'capell-installer.database-tables',
        ));
        $host = trim((string) config('capell-installer.installation_state_cache.host', ''));
        $connection = (string) config('database.default', '');
        $database = (string) config("database.connections.{$connection}.database", '');

        if ($host === '') {
            $configuredUrl = trim((string) config('app.url', ''));
            $parsedHost = parse_url($configuredUrl, PHP_URL_HOST);
            $normalizedHost = rtrim(strtolower(trim(
                is_string($parsedHost) && $parsedHost !== '' ? $parsedHost : $configuredUrl,
            )), '.');
            $host = substr(hash('xxh128', $normalizedHost), 0, 8);
        }

        return sprintf(
            '%s.%s.%s',
            $baseKey !== '' ? $baseKey : 'capell-installer.database-tables',
            $host,
            substr(hash('xxh128', "{$connection}:{$database}"), 0, 8),
        );
    }
}
