<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Health;

enum HealthSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';
}
