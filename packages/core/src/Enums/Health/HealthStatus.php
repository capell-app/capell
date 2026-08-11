<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Health;

enum HealthStatus: string
{
    case Healthy = 'healthy';
    case Warning = 'warning';
    case Failed = 'failed';
    case Error = 'error';
    case TimedOut = 'timed_out';

    public function failed(): bool
    {
        return $this !== self::Healthy;
    }
}
