<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Extensions;

use Spatie\LaravelData\Data;

final class ExtensionOperationsSummaryData extends Data
{
    /**
     * @param  array<string, ExtensionOperationPackageData>  $packages
     * @param  list<ExtensionHealthAlertData>  $alerts
     */
    public function __construct(
        public readonly int $needsAttentionCount,
        public readonly int $blockedCount,
        public readonly int $updatesCount,
        public readonly int $unhealthyCount,
        public readonly int $installedCount,
        public readonly int $uninstalledCount,
        public readonly int $availableCount,
        public readonly array $packages,
        public readonly array $alerts,
    ) {}

    public function package(string $packageName): ?ExtensionOperationPackageData
    {
        return $this->packages[$packageName] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function packageRecord(string $packageName): ?array
    {
        return $this->package($packageName)?->toRecord();
    }

    /** @return list<array<string, mixed>> */
    public function alertRecords(): array
    {
        return array_map(
            fn (ExtensionHealthAlertData $alert): array => $alert->toRecord(),
            $this->alerts,
        );
    }
}
