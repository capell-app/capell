<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages;

use BackedEnum;
use Capell\Admin\Filament\Widgets\Dashboard\AnalyticsInsightsFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\AnalyticsOverviewFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\AnalyticsRecentActivityFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\AnalyticsTrendFilamentWidget;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;
use Override;

final class AnalyticsDashboard extends CapellDashboard
{
    protected static string $routePath = 'analytics';

    protected static ?int $navigationSort = 80;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    #[Override]
    public static function getNavigationLabel(): string
    {
        return (string) __('capell-admin::dashboard.analytics_heading');
    }

    #[Override]
    public static function getNavigationGroup(): string
    {
        return (string) __('capell-admin::navigation.group_reports');
    }

    #[Override]
    public static function canAccess(): bool
    {
        foreach ((new self)->getWidgets() as $widget) {
            $class = $widget instanceof WidgetConfiguration ? $widget->widget : $widget;
            if ($class::canView()) {
                return true;
            }
        }

        return false;
    }

    #[Override]
    public function getTitle(): string
    {
        return self::getNavigationLabel();
    }

    #[Override]
    public function content(Schema $schema): Schema
    {
        return $schema->components([$this->getFiltersFormContentComponent(), $this->getWidgetsContentComponent()]);
    }

    #[Override]
    public function getWidgetsContentComponent(): Grid
    {
        return Grid::make($this->getColumns())->schema($this->getWidgetsSchemaComponents($this->getWidgets()))->columnSpanFull();
    }

    /** @return list<class-string<Widget>|WidgetConfiguration> */
    #[Override]
    public function getWidgets(): array
    {
        return array_values(array_filter(parent::getWidgets(), fn (string|WidgetConfiguration $widget): bool => in_array(
            $widget instanceof WidgetConfiguration ? $widget->widget : $widget,
            [AnalyticsOverviewFilamentWidget::class, AnalyticsTrendFilamentWidget::class, AnalyticsInsightsFilamentWidget::class, AnalyticsRecentActivityFilamentWidget::class],
            true,
        )));
    }
}
