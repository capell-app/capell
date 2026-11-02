<?php

declare(strict_types=1);

namespace Capell\Core\Support\Security\Checks;

use Capell\Core\Contracts\Security\SecurityWeaknessCheck;
use Capell\Core\Data\Security\SecurityPostureInventoryData;
use Capell\Core\Data\Security\SecurityWeaknessFindingData;
use Capell\Core\Enums\Security\SecurityFindingSource;
use Capell\Core\Enums\Security\SecurityWeaknessSeverity;
use Override;

final class InstallerEnabledCheck implements SecurityWeaknessCheck
{
    #[Override]
    public function weaknessId(): string
    {
        return 'installer.still_enabled';
    }

    #[Override]
    public function detect(SecurityPostureInventoryData $inventory): array
    {
        if ($inventory->flag('installer.enabled') !== true || $inventory->flag('installation.complete') !== true) {
            return [];
        }

        return [new SecurityWeaknessFindingData(
            weaknessId: $this->weaknessId(),
            fingerprint: SecurityWeaknessFindingData::fingerprintFor($this->weaknessId()),
            severity: SecurityWeaknessSeverity::High,
            source: SecurityFindingSource::Builtin,
            title: __('capell-core::security.installer.still_enabled.title'),
            summary: __('capell-core::security.installer.still_enabled.summary'),
            remediable: false,
            recommendedAction: __('capell-core::security.installer.still_enabled.action'),
        )];
    }
}
