<?php

declare(strict_types=1);

namespace Capell\Admin\Enums\Reports;

enum ReportFindingSeverity: string
{
    case Critical = 'critical';

    case Warning = 'warning';

    case Info = 'info';
}
