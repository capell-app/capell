<?php

declare(strict_types=1);

namespace Capell\Core\Events;

use Capell\Core\Models\SecurityRemediationRun;

final class SecurityRemediationApplied
{
    public function __construct(public readonly SecurityRemediationRun $run) {}
}
