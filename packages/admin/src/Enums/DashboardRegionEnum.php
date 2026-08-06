<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Filament\Support\Contracts\HasLabel;

enum DashboardRegionEnum: string implements HasLabel
{
    case Pulse = 'pulse';
    case Trends = 'trends';
    case Insights = 'insights';
    case Activity = 'activity';
    case Additional = 'additional';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pulse => (string) __('capell-admin::dashboard.region_pulse'),
            self::Trends => (string) __('capell-admin::dashboard.region_trends'),
            self::Insights => (string) __('capell-admin::dashboard.region_insights'),
            self::Activity => (string) __('capell-admin::dashboard.region_activity'),
            self::Additional => (string) __('capell-admin::dashboard.region_additional'),
        };
    }
}
