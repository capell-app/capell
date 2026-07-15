<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Spatie\LaravelData\Data;

final class ExtensionInstallImpactNodeData extends Data
{
    /**
     * @param  list<string>  $migrations
     * @param  list<string>  $routes
     * @param  list<string>  $scheduledJobs
     * @param  list<string>  $storage
     * @param  list<string>  $permissions
     */
    public function __construct(
        public readonly string $composerName,
        public readonly string $displayName,
        public readonly bool $direct,
        public readonly string $reason,
        public readonly string $maturity,
        public readonly string $entitlement,
        public readonly string $changeOperation,
        public readonly ?string $currentVersion,
        public readonly string $targetVersion,
        public readonly array $migrations,
        public readonly array $routes,
        public readonly array $scheduledJobs,
        public readonly array $storage,
        public readonly array $permissions,
    ) {}
}
