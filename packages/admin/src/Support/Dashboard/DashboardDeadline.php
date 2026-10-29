<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Dashboard;

use Carbon\CarbonImmutable;
use Throwable;

final class DashboardDeadline
{
    public static function timestamp(?string $deadline): int
    {
        if ($deadline === null || trim($deadline) === '') {
            return PHP_INT_MAX;
        }

        try {
            return CarbonImmutable::parse($deadline)->getTimestamp();
        } catch (Throwable) {
            // Invalid provider dates cannot establish an urgent deadline.
            return PHP_INT_MAX;
        }
    }
}
