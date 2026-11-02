<?php

declare(strict_types=1);

namespace Capell\Core\Support\Security;

use Capell\Core\Contracts\Security\SecurityWeaknessAnalyser;
use Capell\Core\Data\Security\SecurityPostureInventoryData;
use Override;

/** The analyser Core binds when no companion package provides one. */
final class NullSecurityWeaknessAnalyser implements SecurityWeaknessAnalyser
{
    #[Override]
    public function name(): string
    {
        return 'none';
    }

    #[Override]
    public function isAvailable(): bool
    {
        return false;
    }

    #[Override]
    public function analyse(SecurityPostureInventoryData $inventory): array
    {
        return [];
    }
}
