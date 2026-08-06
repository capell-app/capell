<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Capell\Core\Enums\Concerns\HasEnumOptions;
use Filament\Support\Contracts\HasLabel;

enum DashboardMetricEnum: string implements HasLabel
{
    use HasEnumOptions;

    case Views = 'views';
    case Searches = 'searches';
    case Both = 'both';

    public function getLabel(): string
    {
        return match ($this) {
            self::Views => (string) __('capell-admin::dashboard.metric_views'),
            self::Searches => (string) __('capell-admin::dashboard.metric_searches'),
            self::Both => (string) __('capell-admin::dashboard.metric_both'),
        };
    }
}
