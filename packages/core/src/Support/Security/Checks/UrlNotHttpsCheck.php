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

final class UrlNotHttpsCheck implements SecurityWeaknessCheck
{
    #[Override]
    public function weaknessId(): string
    {
        return 'app.url_not_https';
    }

    #[Override]
    public function detect(SecurityPostureInventoryData $inventory): array
    {
        if ($inventory->flag('app.env') !== 'production' || $inventory->flag('app.url_scheme') === 'https') {
            return [];
        }

        return [new SecurityWeaknessFindingData(
            weaknessId: $this->weaknessId(),
            fingerprint: SecurityWeaknessFindingData::fingerprintFor($this->weaknessId()),
            severity: SecurityWeaknessSeverity::High,
            source: SecurityFindingSource::Builtin,
            title: __('capell-core::security.app.url_not_https.title'),
            summary: __('capell-core::security.app.url_not_https.summary'),
            evidence: SecurityEvidence::environment($inventory, ['app.env', 'app.url_scheme']),
            remediable: false,
            recommendedAction: __('capell-core::security.app.url_not_https.action'),
        )];
    }
}
