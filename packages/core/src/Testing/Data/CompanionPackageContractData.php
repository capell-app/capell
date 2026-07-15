<?php

declare(strict_types=1);

namespace Capell\Core\Testing\Data;

use Closure;
use Spatie\LaravelData\Data;

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
}
