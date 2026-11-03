<?php

declare(strict_types=1);

namespace Capell\Core\Events;

use Capell\Core\Models\SecurityWeaknessFinding;

final class SecurityWeaknessDetected
{
    public function __construct(public readonly SecurityWeaknessFinding $finding) {}
}
