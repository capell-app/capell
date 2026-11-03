<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support\Security;

use Capell\Core\Contracts\Security\SecurityWeaknessRemediation;
use Capell\Core\Data\Security\SecurityRemediationOutcomeData;
use Capell\Core\Data\Security\SecurityWeaknessFindingData;
use Override;

final class TestRemediation implements SecurityWeaknessRemediation
{
    #[Override]
    public function weaknessId(): string
    {
        return 'app.debug_enabled';
    }

    #[Override]
    public function describe(): string
    {
        return 'Turn off debug mode';
    }

    #[Override]
    public function isReversible(): bool
    {
        return true;
    }

    #[Override]
    public function apply(SecurityWeaknessFindingData $finding): SecurityRemediationOutcomeData
    {
        return new SecurityRemediationOutcomeData(true, 'Changed', ['value' => true], ['value' => false]);
    }
}
