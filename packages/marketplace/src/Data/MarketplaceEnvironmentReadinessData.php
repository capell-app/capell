<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Capell\Marketplace\Enums\MarketplaceInstallCapability;
use Spatie\LaravelData\Data;

/**
 * The single answer to "can this host run an automated Marketplace install, and
 * if not, why not and what does the operator do about it".
 *
 * Preflight, the operations doctor, and every Marketplace surface read this same
 * value so they cannot disagree about the host.
 */
final class MarketplaceEnvironmentReadinessData extends Data
{
    public function __construct(
        public readonly MarketplaceInstallCapability $capability,
        /** @var list<MarketplaceReadinessCheckData> */
        public readonly array $checks = [],
    ) {}

    /**
     * A non-nullable array property is inferred as `required`, which makes
     * validateAndCreate reject the empty list this data legitimately carries
     * when no check has been recorded.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'checks' => ['sometimes', 'array'],
        ];
    }

    public function canInstallAutomatically(): bool
    {
        return $this->capability->allowsAutomatedInstall();
    }

    public function requiresManualInstall(): bool
    {
        return $this->capability === MarketplaceInstallCapability::ManualOnly;
    }

    public function isBlocked(): bool
    {
        return $this->capability === MarketplaceInstallCapability::Blocked;
    }

    /** @return list<MarketplaceReadinessCheckData> */
    public function failedChecks(): array
    {
        return array_values(array_filter(
            $this->checks,
            static fn (MarketplaceReadinessCheckData $check): bool => $check->failed(),
        ));
    }

    /** @return list<MarketplaceReadinessCheckData> */
    public function warnedChecks(): array
    {
        return array_values(array_filter(
            $this->checks,
            static fn (MarketplaceReadinessCheckData $check): bool => $check->warned(),
        ));
    }

    public function check(string $key): ?MarketplaceReadinessCheckData
    {
        foreach ($this->checks as $check) {
            if ($check->key === $key) {
                return $check;
            }
        }

        return null;
    }
}
