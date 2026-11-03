<?php

declare(strict_types=1);

namespace Capell\Core\Support\Security\Checks;

use Capell\Core\Contracts\Security\SecurityWeaknessCheck;
use Capell\Core\Data\Security\SecurityPostureInventoryData;
use Capell\Core\Data\Security\SecurityWeaknessFindingData;
use Capell\Core\Enums\Security\SecurityFindingSource;
use Override;

final class SecurityAdvisoryCheck implements SecurityWeaknessCheck
{
    #[Override]
    public function weaknessId(): string
    {
        return 'extension.security_advisory';
    }

    #[Override]
    public function detect(SecurityPostureInventoryData $inventory): array
    {
        $findings = [];
        foreach ($inventory->advisories as $advisory) {
            $findings[] = new SecurityWeaknessFindingData(
                weaknessId: $this->weaknessId(),
                fingerprint: SecurityWeaknessFindingData::fingerprintFor($this->weaknessId(), $advisory->composerName, $advisory->identifier),
                severity: $advisory->severity,
                source: SecurityFindingSource::Advisory,
                title: __('capell-core::security.extension.security_advisory.title'),
                summary: __('capell-core::security.extension.security_advisory.summary'),
                evidence: ['package' => $advisory->composerName, 'installed_version' => $inventory->package($advisory->composerName)?->version, 'advisory' => $advisory->identifier, 'affected_versions' => $advisory->affectedVersions, 'fixed_version' => $advisory->fixedVersion],
                composerName: $advisory->composerName,
                recommendedAction: __('capell-core::security.extension.security_advisory.action'),
            );
        }

        return $findings;
    }
}
