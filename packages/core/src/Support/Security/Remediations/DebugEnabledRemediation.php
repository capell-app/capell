<?php

declare(strict_types=1);

namespace Capell\Core\Support\Security\Remediations;

use Capell\Core\Contracts\Security\SecurityWeaknessRemediation;
use Capell\Core\Data\Security\SecurityRemediationOutcomeData;
use Capell\Core\Data\Security\SecurityWeaknessFindingData;
use Override;

final class DebugEnabledRemediation implements SecurityWeaknessRemediation
{
    public function __construct(private readonly EnvironmentRemediation $environment) {}

    #[Override]
    public function weaknessId(): string
    {
        return 'app.debug_enabled';
    }

    #[Override]
    public function describe(): string
    {
        return __('capell-core::security.remediation.debug');
    }

    #[Override]
    public function isReversible(): bool
    {
        return true;
    }

    #[Override]
    public function apply(SecurityWeaknessFindingData $finding): SecurityRemediationOutcomeData
    {
        return $this->environment->apply(['APP_DEBUG' => 'false']);
    }
}
