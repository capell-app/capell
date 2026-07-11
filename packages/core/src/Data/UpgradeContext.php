<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Spatie\LaravelData\Data;

class UpgradeContext extends Data
{
    /**
     * @param  array<string, string>  $composerVersions
     * @param  array<string, string>  $ledgerVersions
     * @param  array<int, string>  $appliedStepIds
     */
    public function __construct(
        public readonly array $composerVersions,
        public readonly array $ledgerVersions,
        public readonly array $appliedStepIds,
        public readonly bool $dryRun = false,
        public readonly string $triggeredBy = 'upgrade',
    ) {}

    public function composerVersion(string $package): ?string
    {
        return $this->composerVersions[$package] ?? null;
    }

    public function ledgerVersion(string $package): ?string
    {
        return $this->ledgerVersions[$package] ?? null;
    }

    public function hasApplied(string $stepId): bool
    {
        return in_array($stepId, $this->appliedStepIds, true);
    }

    public function compareVersions(string $left, string $right): int
    {
        if (! preg_match('/^v?\d+(\.\d+)*/i', $left) || ! preg_match('/^v?\d+(\.\d+)*/i', $right)) {
            return 0;
        }

        return version_compare($left, $right);
    }
}
