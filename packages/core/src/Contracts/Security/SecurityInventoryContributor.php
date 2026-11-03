<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Security;

use Capell\Core\Data\Security\SecurityPostureInventoryData;

/**
 * Lets a companion package add posture flags to the security inventory.
 *
 * Tag implementations with {@see self::TAG}. Core merges the returned flags
 * into {@see SecurityPostureInventoryData::$environment} under the
 * contributor's own prefix, so a {@see SecurityWeaknessCheck} can stay a pure
 * function of the inventory while reading package state (settings, last
 * observations) that Core itself does not know about. Flags are scalars only:
 * never secrets, credentials, URLs with credentials or free text.
 *
 * @experimental
 */
interface SecurityInventoryContributor
{
    public const string TAG = 'capell.security.inventory_contributor';

    /**
     * Dotted prefix every returned key must start with, e.g. `password_policy`.
     * Core rejects a contributor whose prefix collides with Core's own keys.
     */
    public function prefix(): string;

    /** @return array<string, bool|int|float|string|null> keys relative to {@see prefix()} */
    public function flags(): array;
}
