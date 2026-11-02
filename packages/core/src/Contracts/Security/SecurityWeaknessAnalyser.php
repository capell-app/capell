<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Security;

use Capell\Core\Data\Security\SecurityPostureInventoryData;
use Capell\Core\Data\Security\SecurityWeaknessFindingData;
use Capell\Core\Enums\Security\SecurityFindingSource;
use Capell\Core\Support\Security\NullSecurityWeaknessAnalyser;

/**
 * Core's single seam onto forward-looking (AI or heuristic) security review.
 *
 * Core binds {@see NullSecurityWeaknessAnalyser};
 * a companion package may rebind this contract to a provider-backed analyser.
 * Core never imports provider runtimes. Findings an analyser returns are
 * recorded with {@see SecurityFindingSource::Analyser}
 * and are never remediated unattended unless they name a registered known
 * weakness whose remediation an operator approved.
 *
 * @experimental
 */
interface SecurityWeaknessAnalyser
{
    public function name(): string;

    public function isAvailable(): bool;

    /** @return list<SecurityWeaknessFindingData> */
    public function analyse(SecurityPostureInventoryData $inventory): array;
}
