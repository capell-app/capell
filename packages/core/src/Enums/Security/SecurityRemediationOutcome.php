<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Security;

enum SecurityRemediationOutcome: string
{
    case Applied = 'applied';
    case AppliedPendingNodes = 'applied_pending_nodes';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
