<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Reporting;

enum DispatchStatus: string
{
    case Reported = 'reported';
    case Suppressed = 'suppressed';
    case Disabled = 'disabled';
    case Fallback = 'fallback';
    case Failed = 'failed';
}
