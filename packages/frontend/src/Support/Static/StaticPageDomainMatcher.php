<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Static;

use Capell\Core\Support\SiteDomains\SiteDomainAddressing;

/** Matches persisted routing constraints without loading models or querying the database. */
final class StaticPageDomainMatcher
{
    /** @param array<string, mixed> $entry */
    public static function schemeMatches(array $entry, string $scheme): bool
    {
        return array_key_exists('scheme', $entry)
            && ($entry['scheme'] === null || (is_string($entry['scheme']) && strtolower($entry['scheme']) === strtolower($scheme)));
    }

    /** @param array<string, mixed> $entry */
    public static function portMatches(array $entry, string $scheme, ?int $port): bool
    {
        // Legacy generation never recorded ports: absence cannot establish
        // ownership, whereas an explicit null denotes the scheme's default.
        if (! array_key_exists('port', $entry)) {
            return false;
        }

        $default = SiteDomainAddressing::defaultPort(strtolower($scheme));

        $entryPort = $entry['port'] ?? $default;

        return is_int($entryPort) && $entryPort === ($port ?? $default);
    }

    /** @param array<string, mixed> $entry */
    public static function matches(array $entry, string $scheme, string $host, string $path, ?int $port = null): bool
    {
        $mount = SiteDomainAddressing::normalizePath($entry['path'] ?? '/');
        $path = SiteDomainAddressing::normalizePath($path);

        return self::schemeMatches($entry, $scheme)
            && is_string($entry['domain'] ?? null)
            && strtolower($entry['domain']) === strtolower($host)
            && self::portMatches($entry, $scheme, $port)
            && is_string($entry['path'] ?? null) && $entry['path'] !== ''
            && ($mount === '/' || $path === $mount || str_starts_with($path, $mount . '/'));
    }

    /** @param array<string, mixed> $entry */
    public static function score(array $entry): int
    {
        // Prefer the longest mount, then explicit scheme and port constraints.
        return strlen(SiteDomainAddressing::normalizePath($entry['path'] ?? '/')) * 4
            + (isset($entry['scheme']) ? 2 : 0)
            + (isset($entry['port']) ? 1 : 0);
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<string, mixed>|null
     */
    public static function best(array $entries, string $scheme, string $host, string $path, ?int $port = null): ?array
    {
        $best = null;
        foreach ($entries as $entry) {
            if (self::matches($entry, $scheme, $host, $path, $port)
                && ($best === null || self::score($entry) > self::score($best))) {
                $best = $entry;
            }
        }

        return $best;
    }
}
