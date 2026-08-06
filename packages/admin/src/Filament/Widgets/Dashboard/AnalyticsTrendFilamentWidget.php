<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Dashboard;

use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Capell\Admin\Filament\Concerns\HasAnalyticsDashboardPeriod;
use Capell\Admin\Filament\Concerns\HasDashboardDateRange;
use Capell\Admin\Support\Dashboard\AdminDashboardDataRequestCache;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Auth\Authenticatable;
use Override;

final class AnalyticsTrendFilamentWidget extends ChartWidget implements CapellFilamentWidgetContract
{
    use GatedByRoleAndSettings;
    use HasAnalyticsDashboardPeriod;
    use HasDashboardDateRange;

    /** @var list<string> */
    protected static array $rolesConfigKeys = ['admin', 'super_admin'];

    protected static string $settingsKey = 'analytics_trend';

    protected static ?int $sort = -90;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = null;

    public function getHeading(): string
    {
        return (string) __('capell-admin::dashboard.analytics_trend');
    }

    #[Override]
    protected function getType(): string
    {
        return 'line';
    }

    /** @return array<string, mixed> */
    #[Override]
    protected function getData(): array
    {
        $actor = auth()->user();

        if (! $actor instanceof Authenticatable) {
            return ['labels' => [], 'datasets' => []];
        }

        $snapshot = resolve(AdminDashboardDataRequestCache::class)
            ->analyticsSnapshot($actor, $this->getAnalyticsDashboardPeriod());

        return [
            'labels' => array_column($snapshot->trend, 'bucket'),
            'datasets' => [[
                'label' => __('capell-admin::dashboard.analytics_views'),
                'data' => array_column($snapshot->trend, 'views'),
                'borderColor' => '#2563eb',
                'backgroundColor' => 'rgba(37, 99, 235, 0.12)',
            ]],
        ];
    }
}
