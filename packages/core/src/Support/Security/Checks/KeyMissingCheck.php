<?php

declare(strict_types=1);

namespace Capell\Core\Support\Security\Checks;

use Capell\Core\Contracts\Security\SecurityWeaknessCheck;
use Capell\Core\Data\Security\SecurityPostureInventoryData;
use Capell\Core\Data\Security\SecurityWeaknessFindingData;
use Capell\Core\Enums\Security\SecurityFindingSource;
use Capell\Core\Enums\Security\SecurityWeaknessSeverity;
use Capell\Core\Support\Security\SecurityEvidence;
use Override;

final class KeyMissingCheck implements SecurityWeaknessCheck
{
    #[Override]
    public function weaknessId(): string
    {
        return 'app.key_missing';
    }

    #[Override]
    public function detect(SecurityPostureInventoryData $inventory): array
    {
        if ($inventory->flag('app.key_present') === true) {
            return [];
        }

        return [new SecurityWeaknessFindingData(
            weaknessId: $this->weaknessId(),
            fingerprint: SecurityWeaknessFindingData::fingerprintFor($this->weaknessId()),
            severity: SecurityWeaknessSeverity::Critical,
            source: SecurityFindingSource::Builtin,
            title: __('capell-core::security.app.key_missing.title'),
            summary: __('capell-core::security.app.key_missing.summary'),
            evidence: SecurityEvidence::environment($inventory, ['app.key_present']),
            remediable: false,
            recommendedAction: __('capell-core::security.app.key_missing.action'),
        )];
    }
}
