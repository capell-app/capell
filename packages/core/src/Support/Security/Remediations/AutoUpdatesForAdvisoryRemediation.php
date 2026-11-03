<?php

declare(strict_types=1);

namespace Capell\Core\Support\Security\Remediations;

use Capell\Core\Contracts\Security\SecurityWeaknessRemediation;
use Capell\Core\Data\Security\SecurityRemediationOutcomeData;
use Capell\Core\Data\Security\SecurityWeaknessFindingData;
use Capell\Core\Enums\ExtensionAutoUpdatePolicyEnum;
use Capell\Core\Models\CapellExtension;
use Override;

final class AutoUpdatesForAdvisoryRemediation implements SecurityWeaknessRemediation
{
    #[Override]
    public function weaknessId(): string
    {
        return 'extension.auto_updates_off_for_advisory';
    }

    #[Override]
    public function describe(): string
    {
        return __('capell-core::security.remediation.updates');
    }

    #[Override]
    public function isReversible(): bool
    {
        return true;
    }

    #[Override]
    public function apply(SecurityWeaknessFindingData $finding): SecurityRemediationOutcomeData
    {
        $extension = $finding->composerName === null ? null : CapellExtension::query()->where('composer_name', $finding->composerName)->lockForUpdate()->first();
        if ($extension === null || $extension->auto_update_policy !== ExtensionAutoUpdatePolicyEnum::None) {
            return SecurityRemediationOutcomeData::skipped(__('capell-core::security.remediation.no_update_needed'));
        }

        $extension->update(['auto_update_policy' => ExtensionAutoUpdatePolicyEnum::Security]);

        return new SecurityRemediationOutcomeData(true, __('capell-core::security.remediation.changed'), ['auto_update_policy' => 'none'], ['auto_update_policy' => 'security']);
    }
}
