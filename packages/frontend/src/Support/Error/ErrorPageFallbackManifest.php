<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Error;

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
     * Path to the manifest, relative to the storage directory.
     */
    private const string MANIFEST_RELATIVE_PATH = 'framework/capell-error-pages-fallback.json';

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
        $manifest = self::manifest();

        $hostLogo = self::nestedString($manifest, ['hosts', strtolower($host), 'logo_url']);

        if ($hostLogo !== null) {
            return $hostLogo;
        }

        return self::nestedString($manifest, ['default', 'logo_url']);
    }

    /**
     * @return array{headline: ?string, description: ?string}|null
     */
    public static function copy(string $host, string $status): ?array
    {
        $manifest = self::manifest();

        $hostCopy = self::nestedArray($manifest, ['hosts', strtolower($host), 'copy', $status]);

        if ($hostCopy !== null) {
            return self::normalizeCopy($hostCopy);
        }

        $defaultCopy = self::nestedArray($manifest, ['default', 'copy', $status]);

        if ($defaultCopy !== null) {
            return self::normalizeCopy($defaultCopy);
        }

        return null;
    }

    /**
     * Read the logo and copy from one coherent manifest snapshot.
     *
     * @return array{logo_url: ?string, copy: array{headline: ?string, description: ?string}|null}
     */
    public static function forHost(string $host, string $status): array
    {
        $manifest = self::manifest();
        $normalizedHost = strtolower($host);

        $logoUrl = self::nestedString($manifest, ['hosts', $normalizedHost, 'logo_url'])
            ?? self::nestedString($manifest, ['default', 'logo_url']);
        $copy = self::nestedArray($manifest, ['hosts', $normalizedHost, 'copy', $status])
            ?? self::nestedArray($manifest, ['default', 'copy', $status]);

        return [
            'logo_url' => $logoUrl,
            'copy' => $copy !== null ? self::normalizeCopy($copy) : null,
        ];
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
        $path = storage_path(self::MANIFEST_RELATIVE_PATH);

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
