<?php

declare(strict_types=1);

namespace Capell\Core\Support\Security\Checks;

use Capell\Core\Contracts\Security\SecurityWeaknessCheck;
use Capell\Core\Data\Security\SecurityPostureInventoryData;
use Capell\Core\Data\Security\SecurityWeaknessFindingData;
use Capell\Core\Enums\Security\SecurityFindingSource;
use Capell\Core\Enums\Security\SecurityWeaknessSeverity;
use Override;

final class DebugEnabledCheck implements SecurityWeaknessCheck
{
    #[Override]
    public function weaknessId(): string
    {
        return 'app.debug_enabled';
    }

    #[Override]
    public function detect(SecurityPostureInventoryData $inventory): array
    {
        if ($inventory->flag('app.debug') !== true) {
            return [];
        }

        return [new SecurityWeaknessFindingData(
            weaknessId: $this->weaknessId(),
            fingerprint: SecurityWeaknessFindingData::fingerprintFor($this->weaknessId()),
            severity: $inventory->flag('app.env') === 'production' ? SecurityWeaknessSeverity::Critical : SecurityWeaknessSeverity::High,
            source: SecurityFindingSource::Builtin,
            title: __('capell-core::security.app.debug_enabled.title'),
            summary: __('capell-core::security.app.debug_enabled.summary'),
            remediable: true,
            recommendedAction: __('capell-core::security.app.debug_enabled.action'),
        )];
    }
}
