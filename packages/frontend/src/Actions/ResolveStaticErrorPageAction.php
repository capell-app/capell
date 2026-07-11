<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Frontend\Contracts\StaticErrorPageStore;
use Capell\Frontend\Support\Error\ErrorPageManifestStore;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static ?string run(string $scheme, string $host, string $pathInfo, string $status)
 */
final class ResolveStaticErrorPageAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly ErrorPageManifestStore $manifestStore,
    ) {}

    public function handle(string $scheme, string $host, string $pathInfo, string $status): ?string
    {
        if (! app()->bound(StaticErrorPageStore::class)) {
            return null;
        }

        $requestPath = $this->normalizePath($pathInfo);
        $normalizedHost = strtolower($host);
        $normalizedScheme = strtolower($scheme);

        $bestEntry = null;
        $bestMatchLength = -1;

        foreach ($this->flattenEntries() as $entry) {
            if (strtolower((string) ($entry['scheme'] ?? '')) !== $normalizedScheme) {
                continue;
            }

            if (strtolower((string) ($entry['domain'] ?? '')) !== $normalizedHost) {
                continue;
            }

            if ((string) ($entry['status'] ?? '') !== $status) {
                continue;
            }

            $entryPath = $this->normalizePath($entry['path'] ?? '/');

            if ($entryPath !== '/' && $requestPath !== $entryPath && ! str_starts_with($requestPath, rtrim($entryPath, '/') . '/')) {
                continue;
            }

            $matchLength = strlen($entryPath);

            if ($matchLength > $bestMatchLength) {
                $bestEntry = $entry;
                $bestMatchLength = $matchLength;
            }
        }

        if ($bestEntry === null) {
            return null;
        }

        $path = resolve(StaticErrorPageStore::class)->path((string) ($bestEntry['file'] ?? ''));

        if ($path === null || ! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    /** @return array<int, array<string, mixed>> */
    private function flattenEntries(): array
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

    private function normalizePath(mixed $path): string
    {
        if (! is_string($path) || $path === '') {
            return '/';
        }

        return '/' . trim($path, '/');
    }
}
