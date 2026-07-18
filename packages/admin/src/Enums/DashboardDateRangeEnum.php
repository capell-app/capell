<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Filament\Support\Contracts\HasLabel;

enum DashboardDateRangeEnum: string implements HasLabel
{
    case Today = 'today';
    case ThisWeek = 'this_week';
    case ThisMonth = 'this_month';
    case Last30Days = 'last_30_days';
    case ThisYear = 'this_year';

    public function getLabel(): string
    {
        return match ($this) {
            self::Today => (string) __('capell-admin::dashboard.filter_today'),
            self::ThisWeek => (string) __('capell-admin::dashboard.filter_this_week'),
            self::ThisMonth => (string) __('capell-admin::dashboard.filter_this_month'),
            self::Last30Days => (string) __('capell-admin::dashboard.filter_last_30_days'),
            self::ThisYear => (string) __('capell-admin::dashboard.filter_this_year'),
        };
    }
}
