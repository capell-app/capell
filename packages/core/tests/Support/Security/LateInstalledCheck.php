<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support\Security;

use Capell\Core\Contracts\Security\SecurityWeaknessCheck;
use Capell\Core\Data\Security\SecurityPostureInventoryData;
use Override;

/** A package-owned check that reaches the container after Core's boot-time tag collection. */
final class LateInstalledCheck implements SecurityWeaknessCheck
{
    #[Override]
    public function weaknessId(): string
    {
        return 'late_package.example';
    }

    #[Override]
    public function detect(SecurityPostureInventoryData $inventory): array
    {
        return [];
    }
}
