<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Error;

use Capell\Frontend\Enums\StaticErrorPageResolutionReason;

/**
 * The single owner of the static error page matching predicates.
 *
 * Both the resolver (hot path, anonymous requests) and the diagnostics command
 * evaluate entries through here, so a diagnosis can never disagree with what
 * the resolver actually did.
 */
final class StaticErrorPageMatcher
{
    public function __construct(
        private readonly ErrorPageManifestStore $manifestStore,
    ) {}

    /**
     * Every manifest entry, flattened across sites.
     *
     * @return array<int, array<string, mixed>>
     */
    public function entries(): array
    {
        $manifest = $this->manifestStore->read();
        $entries = [];

        foreach (($manifest['sites'] ?? []) as $site) {
            if (! is_array($site)) {
                continue;
            }

            foreach (($site['entries'] ?? []) as $entry) {
                if (is_array($entry)) {
                    $entries[] = $entry;
                }
            }
        }

        return $entries;
    }

    /**
     * The first predicate that rejects this entry, or null when it matches.
     *
     * Deliberately returns an enum rather than a rich object: this runs once
     * per manifest entry on the error path of every anonymous request.
     *
     * @param  array<string, mixed>  $entry
     */
    public function reject(array $entry, string $scheme, string $host, string $pathInfo, string $status): ?StaticErrorPageResolutionReason
    {
        if ($this->entryScheme($entry) !== strtolower($scheme)) {
            return StaticErrorPageResolutionReason::SchemeMismatch;
        }

        if ($this->entryDomain($entry) !== strtolower($host)) {
            return StaticErrorPageResolutionReason::DomainMismatch;
        }

        if ($this->entryStatus($entry) !== $status) {
            return StaticErrorPageResolutionReason::StatusMismatch;
        }

        if (! $this->pathCovers($this->entryPath($entry), $this->normalizePath($pathInfo))) {
            return StaticErrorPageResolutionReason::PathMismatch;
        }

        return null;
    }

    /** @param array<string, mixed> $entry */
    public function entryScheme(array $entry): string
    {
        return strtolower((string) ($entry['scheme'] ?? ''));
    }

    /** @param array<string, mixed> $entry */
    public function entryDomain(array $entry): string
    {
        return strtolower((string) ($entry['domain'] ?? ''));
    }

    /** @param array<string, mixed> $entry */
    public function entryStatus(array $entry): string
    {
        return (string) ($entry['status'] ?? '');
    }

    /** @param array<string, mixed> $entry */
    public function entryPath(array $entry): string
    {
        return $this->normalizePath($entry['path'] ?? '/');
    }

    /** @param array<string, mixed> $entry */
    public function entryFile(array $entry): string
    {
        return (string) ($entry['file'] ?? '');
    }

    /**
     * A longer matching entry path wins, so `/shop` beats `/` for `/shop/x`.
     */
    public function matchLength(string $entryPath): int
    {
        return strlen($entryPath);
    }

    public function pathCovers(string $entryPath, string $requestPath): bool
    {
        if ($entryPath === '/') {
            return true;
        }

        return $requestPath === $entryPath
            || str_starts_with($requestPath, rtrim($entryPath, '/') . '/');
    }

    public function normalizePath(mixed $path): string
    {
        if (! is_string($path) || $path === '') {
            return '/';
        }

        return '/' . trim($path, '/');
    }
}
