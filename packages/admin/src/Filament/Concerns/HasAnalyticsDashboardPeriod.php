<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns;

use Capell\Admin\Settings\AdminSettings;

trait HasAnalyticsDashboardPeriod
{
    protected function getAnalyticsDashboardPeriod(): string
    {
        if ($this->hasDashboardPeriodFilter()) {
            return $this->getDashboardPeriod();
        }

        return match (max(1, min(365, AdminSettings::instance()->analytics_default_period_days))) {
            1 => 'today',
            7 => 'this_week',
            30 => 'last_30_days',
            365 => 'this_year',
            default => 'last_30_days',
        };
    }
}
