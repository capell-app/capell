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

final class InsecureCookieCheck implements SecurityWeaknessCheck
{
    #[Override]
    public function weaknessId(): string
    {
        return 'session.insecure_cookie';
    }

    #[Override]
    public function detect(SecurityPostureInventoryData $inventory): array
    {
        if (($inventory->flag('app.url_scheme') !== 'https' || $inventory->flag('session.secure_cookie') === true) && $inventory->flag('session.http_only') === true) {
            return [];
        }

        return [new SecurityWeaknessFindingData(
            weaknessId: $this->weaknessId(),
            fingerprint: SecurityWeaknessFindingData::fingerprintFor($this->weaknessId()),
            severity: SecurityWeaknessSeverity::High,
            source: SecurityFindingSource::Builtin,
            title: __('capell-core::security.session.insecure_cookie.title'),
            summary: __('capell-core::security.session.insecure_cookie.summary'),
            evidence: SecurityEvidence::environment($inventory, ['app.url_scheme', 'session.secure_cookie', 'session.http_only']),
            remediable: $inventory->flag('app.url_scheme') === 'https',
            recommendedAction: __('capell-core::security.session.insecure_cookie.action'),
        )];
    }
}
