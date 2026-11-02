<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Security;

enum SecurityFindingStatus: string
{
    /** Detected on the latest scan and not yet dealt with. */
    case Open = 'open';

    /** An approved remediation was applied; the next scan confirms whether it held. */
    case Remediated = 'remediated';

    /** No longer detected by the latest scan. */
    case Resolved = 'resolved';

    /** An operator chose to accept the risk; stays hidden until the evidence changes. */
    case Dismissed = 'dismissed';

    public function isActive(): bool
    {
        return $this === self::Open || $this === self::Remediated;
    }
}
