<?php

declare(strict_types=1);

namespace Capell\Core\Data\Security;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * Everything a weakness check or analyser is allowed to know about this
 * installation, collected once per scan.
 *
 * Checks and analysers read this object instead of config(), env() or the
 * database so that a scan is reproducible and an analyser (including a remote
 * AI service bound by a companion package) never receives secrets: the
 * inventory carries facts about posture ("debug is on", "HTTPS is enforced"),
 * never credential values.
 *
 * @property array<string, bool|int|float|string|null> $environment
 */
final class SecurityPostureInventoryData extends Data
{
    /**
     * @param  list<SecurityInventoryPackageData>  $installedPackages
     * @param  array<string, bool|int|float|string|null>  $environment
     * @param  list<SecurityAdvisoryData>  $advisories
     * @param  list<string>  $failures
     */
    public function __construct(
        public readonly ?string $capellVersion,
        public readonly array $installedPackages,
        public readonly array $environment,
        public readonly array $advisories,
        public readonly CarbonImmutable $collectedAt,
        public readonly int $advisorySourcesRun = 0,
        public readonly array $failures = [],
        public readonly ?string $scanId = null,
    ) {}

    public function package(string $composerName): ?SecurityInventoryPackageData
    {
        foreach ($this->installedPackages as $package) {
            if ($package->composerName === $composerName) {
                return $package;
            }
        }

        return null;
    }

    public function flag(string $key, bool|int|float|string|null $default = null): bool|int|float|string|null
    {
        return array_key_exists($key, $this->environment) ? $this->environment[$key] : $default;
    }
}
