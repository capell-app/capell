<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Diagnostics;

enum DoctorCheckSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';
}
