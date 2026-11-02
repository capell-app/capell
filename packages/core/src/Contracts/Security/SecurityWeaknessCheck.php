<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Security;

use Capell\Core\Data\Security\SecurityPostureInventoryData;
use Capell\Core\Data\Security\SecurityWeaknessFindingData;
use Capell\Core\Support\Security\SecurityWeaknessRegistry;

/**
 * A deterministic detector for one known kind of weakness.
 *
 * Tag implementations with {@see self::TAG}; Core resolves them into the
 * {@see SecurityWeaknessRegistry} at boot. A
 * check reads only the inventory it is given, performs no writes, and returns
 * one finding per occurrence (or none).
 *
 * @experimental
 */
interface SecurityWeaknessCheck
{
    public const string TAG = 'capell.security.weakness_check';

    /** Stable dotted id, e.g. `app.debug_enabled`; shared with its remediation. */
    public function weaknessId(): string;

    /** @return list<SecurityWeaknessFindingData> */
    public function detect(SecurityPostureInventoryData $inventory): array;
}
