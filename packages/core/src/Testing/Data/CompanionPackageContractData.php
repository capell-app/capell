<?php

declare(strict_types=1);

namespace Capell\Core\Testing\Data;

use Closure;
use Spatie\LaravelData\Data;
use Symfony\Component\Finder\Finder;

final class CompanionPackageContractData extends Data
{
    /**
     * @param  list<string>  $migrations
     * @param  Closure(): bool|null  $lifecycleAssertion
     * @param  Closure(): bool|null  $authorizationAssertion
     * @param  Closure(): bool|null  $cacheInvalidationAssertion
     * @param  Closure(): string|null  $publicRender
     */
    public function __construct(
        public readonly string $packageRoot,
        public readonly string $manifestPath,
        public readonly ?string $providerClass,
        public readonly array $migrations = [],
        public readonly ?Closure $lifecycleAssertion = null,
        public readonly ?Closure $authorizationAssertion = null,
        public readonly ?Closure $cacheInvalidationAssertion = null,
        public readonly ?Closure $publicRender = null,
    ) {}

    /**
     * The contract a package declares in its own files: its `capell.json`, the first runtime provider the manifest
     * names (or the first metadata provider when it has no runtime bucket) and every migration under
     * `database/migrations`. Behaviour assertions that need the package booted are passed in by its own test.
     *
     * @param  Closure(): bool|null  $lifecycleAssertion
     * @param  Closure(): bool|null  $authorizationAssertion
     * @param  Closure(): bool|null  $cacheInvalidationAssertion
     * @param  Closure(): string|null  $publicRender
     */
    public static function forPackage(
        string $packageRoot,
        ?Closure $lifecycleAssertion = null,
        ?Closure $authorizationAssertion = null,
        ?Closure $cacheInvalidationAssertion = null,
        ?Closure $publicRender = null,
    ): self {
        $packageRoot = rtrim($packageRoot, DIRECTORY_SEPARATOR);
        $manifestPath = $packageRoot . '/capell.json';
        $manifest = json_decode((string) @file_get_contents($manifestPath), true);
        $providers = is_array($manifest) && is_array($manifest['providers'] ?? null) ? $manifest['providers'] : [];
        $providerClass = null;

        foreach (['runtime', 'metadata'] as $bucket) {
            $candidate = is_array($providers[$bucket] ?? null) ? ($providers[$bucket][0] ?? null) : null;

            if (is_string($candidate) && $candidate !== '') {
                $providerClass = $candidate;

                break;
            }
        }

        $migrations = [];

        if (is_dir($packageRoot . '/database/migrations')) {
            foreach ((new Finder)->files()->in($packageRoot . '/database/migrations')->name('*.php')->sortByName() as $file) {
                $migrations[] = 'database/migrations/' . str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());
            }
        }

        return new self(
            packageRoot: $packageRoot,
            manifestPath: $manifestPath,
            providerClass: $providerClass,
            migrations: $migrations,
            lifecycleAssertion: $lifecycleAssertion,
            authorizationAssertion: $authorizationAssertion,
            cacheInvalidationAssertion: $cacheInvalidationAssertion,
            publicRender: $publicRender,
        );
    }
}
