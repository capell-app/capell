<?php

declare(strict_types=1);

namespace Capell\Core\Support\Security\Checks;

use Capell\Core\Contracts\Security\SecurityWeaknessCheck;
use Capell\Core\Data\Security\SecurityInventoryPackageData;
use Capell\Core\Data\Security\SecurityPostureInventoryData;
use Capell\Core\Data\Security\SecurityWeaknessFindingData;
use Capell\Core\Enums\ExtensionAutoUpdatePolicyEnum;
use Capell\Core\Enums\Security\SecurityFindingSource;
use Capell\Core\Enums\Security\SecurityWeaknessSeverity;
use Override;

final class AutoUpdatesOffForAdvisoryCheck implements SecurityWeaknessCheck
{
    #[Override]
    public function weaknessId(): string
    {
        return 'extension.auto_updates_off_for_advisory';
    }

    #[Override]
    public function detect(SecurityPostureInventoryData $inventory): array
    {
        $findings = [];
        foreach ($inventory->advisories as $advisory) {
            $package = $inventory->package($advisory->composerName);
            if (! $package instanceof SecurityInventoryPackageData) {
                continue;
            }

            if ($package->autoUpdatePolicy !== ExtensionAutoUpdatePolicyEnum::None->value) {
                continue;
            }

            if (isset($findings[$advisory->composerName])) {
                continue;
            }

            $findings[$advisory->composerName] = new SecurityWeaknessFindingData(
                weaknessId: $this->weaknessId(),
                fingerprint: SecurityWeaknessFindingData::fingerprintFor($this->weaknessId(), $advisory->composerName),
                severity: SecurityWeaknessSeverity::Medium,
                source: SecurityFindingSource::Builtin,
                title: __('capell-core::security.extension.auto_updates_off_for_advisory.title'),
                summary: __('capell-core::security.extension.auto_updates_off_for_advisory.summary'),
                composerName: $advisory->composerName,
                remediable: true,
                recommendedAction: __('capell-core::security.extension.auto_updates_off_for_advisory.action'),
            );
        }

        return array_values($findings);
    }
}
