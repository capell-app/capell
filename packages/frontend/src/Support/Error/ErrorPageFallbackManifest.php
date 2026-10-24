<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Error;

use Capell\Frontend\Support\Static\StaticPageDomainMatcher;
use Illuminate\Container\Container;
use Illuminate\Http\Request;

/**
 * DB-free reader for the pre-rendered error-page fallback manifest.
 *
 * The manifest is produced by a separate pipeline and lets the inline error
 * blades render branded copy and logo even when the database is unavailable.
 * This reader MUST NOT throw, touch the database, or load any Eloquent model.
 */
final class ErrorPageFallbackManifest
{
    /**
     * Retained for consumers which cleared the former process-static cache.
     * Reads are now always fresh, so there is no state to flush.
     */
    public static function flush(): void
    {
        // No-op: the manifest is read from disk for every operation.
    }

    public static function logoUrl(string $host): ?string
    {
        return self::forHost($host, '500')['logo_url'];
    }

    /** @return array{headline: ?string, description: ?string}|null */
    public static function copy(string $host, string $status): ?array
    {
        return self::forHost($host, $status)['copy'];
    }

    /**
     * Read the logo and copy from one coherent manifest snapshot.
     *
     * @return array{logo_url: ?string, copy: array{headline: ?string, description: ?string}|null}
     */
    public static function forHost(string $host, string $status, ?string $scheme = null, ?int $port = null, ?string $path = null): array
    {
        // Published error views still use the host-only API. Resolve their
        // missing routing context without loading any site or frontend state.
        $container = Container::getInstance();
        $request = $container->bound('request') ? $container->make('request') : null;
        if ($request instanceof Request && strtolower($host) === strtolower($request->getHost())) {
            $port ??= func_num_args() < 4 && ($scheme === null || strtolower($scheme) === $request->getScheme())
                ? $request->getPort() : null;
            $scheme ??= $request->getScheme();
            $path ??= $request->getPathInfo();
        }

        $manifest = self::manifest();
        $entry = self::entry($manifest, $host, $scheme ?? 'https', $port, $path ?? '/');
        $copy = self::nestedArray($entry, ['copy', $status]);
        $logo = self::nestedString($entry, ['logo_url']);
        // Old workers preserve the version during read-modify-write. Only the
        // new API writes this override namespace; legacy hosts/default are unsafe.
        $copy = self::nestedArray($manifest, ['explicit_overrides', 'hosts', strtolower($host), 'copy', $status])
            ?? $copy
            ?? self::nestedArray($manifest, ['explicit_overrides', 'default', 'copy', $status]);
        $logo = self::nestedString($manifest, ['explicit_overrides', 'hosts', strtolower($host), 'logo_url'])
            ?? $logo
            ?? self::nestedString($manifest, ['explicit_overrides', 'default', 'logo_url']);

        return [
            'logo_url' => $logo,
            'copy' => $copy !== null ? self::normalizeCopy($copy) : null,
        ];
    }

    /**
     * @param  array<mixed>  $manifest
     * @return array<mixed>
     */
    public static function entry(array $manifest, string $host, string $scheme = 'https', ?int $port = null, string $path = '/'): array
    {
        if (($manifest['version'] ?? null) === 2) {
            $entries = [];
            $sites = $manifest['sites'] ?? [];
            foreach (is_array($sites) ? $sites : [] as $site) {
                if (! is_array($site)) {
                    continue;
                }

                $domains = $site['domains'] ?? [];
                foreach (is_array($domains) ? $domains : [] as $domain) {
                    if (is_array($domain)) {
                        $entries[] = $domain;
                    }
                }
            }

            return StaticPageDomainMatcher::best($entries, $scheme, $host, $path, $port) ?? [];
        }

        return [];
    }

    /**
     * @return array<mixed>
     */
    private static function manifest(): array
    {
        return self::read() ?? [];
    }

    /**
     * @return array<mixed>|null
     */
    private static function read(): ?array
    {
        $path = ErrorPageManifestLocation::fallback();

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false || $contents === '') {
            return null;
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<mixed>  $manifest
     * @param  list<string>  $keys
     */
    private static function nestedString(array $manifest, array $keys): ?string
    {
        $value = self::nestedValue($manifest, $keys);

        return (is_string($value) && $value !== '') ? $value : null;
    }

    /**
     * @param  array<mixed>  $manifest
     * @param  list<string>  $keys
     * @return array<mixed>|null
     */
    private static function nestedArray(array $manifest, array $keys): ?array
    {
        $value = self::nestedValue($manifest, $keys);

        return is_array($value) ? $value : null;
    }

    /**
     * @param  array<mixed>  $manifest
     * @param  list<string>  $keys
     */
    private static function nestedValue(array $manifest, array $keys): mixed
    {
        $current = $manifest;

        foreach ($keys as $key) {
            if (! is_array($current) || ! array_key_exists($key, $current)) {
                return null;
            }

            $current = $current[$key];
        }

        return $current;
    }

    /**
     * @param  array<mixed>  $copy
     * @return array{headline: ?string, description: ?string}
     */
    private static function normalizeCopy(array $copy): array
    {
        $headline = $copy['headline'] ?? null;
        $description = $copy['description'] ?? null;

        return [
            'headline' => (is_string($headline) && $headline !== '') ? $headline : null,
            'description' => (is_string($description) && $description !== '') ? $description : null,
        ];
    }
}
