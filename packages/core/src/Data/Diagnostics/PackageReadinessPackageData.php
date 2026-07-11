<?php

declare(strict_types=1);

namespace Capell\Core\Data\Diagnostics;

use Spatie\LaravelData\Data;

final class PackageReadinessPackageData extends Data
{
    /**
     * @param  list<PackageReadinessCheckData>  $checks
     */
    public function __construct(
        public readonly string $packageName,
        public readonly string $label,
        public readonly array $checks,
    ) {}

    public function ready(): bool
    {
        return ! array_any($this->checks, fn (PackageReadinessCheckData $check): bool => $check->blocksReadiness() || $check->warnsReadiness());
    }

    public function criticalCount(): int
    {
        return count(array_filter($this->checks, fn (PackageReadinessCheckData $check): bool => $check->blocksReadiness()));
    }

    public function warningCount(): int
    {
        return count(array_filter($this->checks, fn (PackageReadinessCheckData $check): bool => $check->warnsReadiness()));
    }
}
