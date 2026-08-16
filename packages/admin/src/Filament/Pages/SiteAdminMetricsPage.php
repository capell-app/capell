<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages;

use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Override;

final class SiteAdminMetricsPage extends Page
{
    use HasPageShield;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::ChartBarSquare;

    protected static ?string $slug = 'site-admin-metrics';

    protected static ?int $navigationSort = 20;

    protected string $view = 'capell-admin::filament.pages.site-admin-metrics';

    #[Override]
    public static function getNavigationLabel(): string
    {
        return (string) __('capell-admin::navigation.site_admin_metrics');
    }

    #[Override]
    public static function getNavigationGroup(): string
    {
        return (string) __('capell-admin::navigation.group_monitoring');
    }

    #[Override]
    public function getTitle(): string
    {
        return (string) __('capell-admin::metrics.title');
    }

    #[Override]
    public function getSubheading(): string
    {
        return (string) __('capell-admin::metrics.description');
    }
}
