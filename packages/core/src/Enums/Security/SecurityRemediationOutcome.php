<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Security;

enum SecurityRemediationOutcome: string
{
    case Applied = 'applied';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
