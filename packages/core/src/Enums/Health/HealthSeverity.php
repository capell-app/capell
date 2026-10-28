<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Health;

/** @internal */
enum HealthSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';
}
