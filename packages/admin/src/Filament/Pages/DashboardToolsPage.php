<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages;

use BackedEnum;
use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Enums\DashboardRegionEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Widgets\Dashboard\NeedsAttentionFilamentWidget;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;
use Override;

final class DashboardToolsPage extends CapellDashboard
{
    protected static string $routePath = 'dashboard-tools';

    protected static ?int $navigationSort = 80;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    #[Override]
    public static function getNavigationLabel(): string
    {
        return (string) __('capell-admin::dashboard.tools_heading');
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
        $overview = [NeedsAttentionFilamentWidget::class];
        $regions = [];
        foreach ([DashboardRegionEnum::Pulse, DashboardRegionEnum::Trends, DashboardRegionEnum::Insights, DashboardRegionEnum::Activity] as $region) {
            $regions = [...$regions, ...CapellAdmin::getDashboardFilamentWidgetsByRegion(DashboardEnum::Main, $region)];
        }

        return array_values(array_filter(parent::getWidgets(), function (string|WidgetConfiguration $widget) use ($overview, $regions): bool {
            $class = $widget instanceof WidgetConfiguration ? $widget->widget : $widget;

            return ! $this->isWelcomeWidget($widget)
                && ! in_array($class, [...$overview, ...$regions], true);
        }));
    }

    #[Override]
    protected function periodFilterLabel(): string
    {
        return (string) __('capell-admin::dashboard.tools_period');
    }
}
