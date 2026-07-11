<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Spatie\LaravelData\Data;

final class CreateMarketplaceInstallFlowSessionData extends Data
{
    /**
     * @param  array<int, array<string, mixed>>  $selectedExtensions
     * @param  array<string, mixed>  $installOptions
     * @param  array<string, mixed>  $dependencySnapshot
     * @param  array<string, mixed>  $userContext
     */
    public function __construct(
        public readonly array $selectedExtensions,
        public readonly array $installOptions,
        public readonly array $dependencySnapshot,
        public readonly array $userContext,
        public readonly string $returnUrl,
    ) {}
}
