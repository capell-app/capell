<?php

declare(strict_types=1);

namespace Capell\Core\Support\HostMonitor;

/** How long hourly host rollups are kept. Bounded so a typo cannot keep them forever or delete them at once. */
final class HostLoadRetention
{
    public const int DEFAULT_DAYS = 90;

    public const int MAXIMUM_DAYS = 365;

    public static function days(): int
    {
        $configured = config('capell.health.host.history.retention_days', self::DEFAULT_DAYS);
        $days = is_numeric($configured) ? (int) $configured : self::DEFAULT_DAYS;

        return min(self::MAXIMUM_DAYS, max(1, $days));
    }
}
