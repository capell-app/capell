<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Health;

/** @experimental */
enum HealthSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';
}
