<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Reporting;

enum IncidentStatus: string
{
    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case Escalated = 'escalated';
    case Resolved = 'resolved';
}
