<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages;

use BackedEnum;
use Capell\Admin\Enums\DashboardDateRangeEnum;
use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Admin\Settings\AdminSettings;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Site;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Filament\Actions\Action;
use Filament\Forms\Components\ToggleButtons;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;
use Override;

class CapellDashboard extends Dashboard
{
    use HasFiltersForm {
        updatedFilters as filtersFormUpdatedFilters;
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static bool $shouldRegisterNavigation = true;

    protected static ?int $navigationSort = -100;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            ToggleButtons::make('date_range')
                ->options(DashboardDateRangeEnum::options())
                ->columnSpanFull()
                ->default('this_week')
                ->extraAttributes([
                    'style' => 'grid-auto-columns: max-content; max-width: 100%; white-space: nowrap; width: max-content;',
                ])
                ->extraFieldWrapperAttributes(['class' => 'w-full'])
                ->inline()
                ->grouped(),
        ]);
    }

    public function updatedFilters(): void
    {
        $this->filtersFormUpdatedFilters();
        $period = (string) data_get($this->filters, 'date_range', 'last_30_days');
        $this->dispatch('dashboardFilterChanged', period: $period);
    }

    /**
     * @return array<string, int>
     */
    #[Override]
    public function getColumns(): array
    {
        return [
            'default' => 1,
            'lg' => 2,
        ];
    }

    #[Override]
    public function getFiltersFormContentComponent(): Component
    {
        return parent::getFiltersFormContentComponent()
            ->columnSpanFull();
    }

    #[Override]
    public function getWidgetsContentComponent(): Grid
    {
        return Grid::make($this->getColumns())
            ->schema(fn (): array => $this->getWidgetsSchemaComponents($this->getWidgets()))
            ->columnSpanFull();
    }

    /**
     * @return array<class-string<Widget>|WidgetConfiguration>
     */
    #[Override]
    public function getWidgets(): array
    {
        if (! CapellCore::getPackage(AdminServiceProvider::$packageName)->isInstalled()
            || ! resolve(RuntimeSchemaState::class)->hasTable((new Site)->getTable())
            || ! Site::query()->exists()) {
            return CapellAdmin::getDashboardFilamentWidgets(DashboardEnum::NotInstalled);
        }

        return $this->configuredDashboardFilamentWidgets(CapellAdmin::getDashboardFilamentWidgets(DashboardEnum::Main));
    }

    /**
     * @return array<int, Action>
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        return array_values(array_filter([
            $this->upgradeAction(),
        ]));
    }

    /**
     * @param  list<class-string<Widget>>  $widgets
     * @return list<class-string<Widget>|WidgetConfiguration>
     */
    private function configuredDashboardFilamentWidgets(array $widgets): array
    {
        $settings = resolve(AdminSettings::class);

        return array_values(collect($widgets)
            ->filter(function (string $widgetClass) use ($settings): bool {
                if (! method_exists($widgetClass, 'settingsKey')) {
                    return true;
                }

                $settingsKey = $widgetClass::settingsKey();

                return ! is_string($settingsKey)
                    || $settingsKey === ''
                    || $settings->isWidgetEnabled($settingsKey);
            })
            ->values()
            ->all());
    }

    private function upgradeAction(): ?Action
    {
        if (! UpgradePage::canAccess()) {
            return null;
        }

        $badge = UpgradePage::getNavigationBadge();

        if ($badge === null || $badge === '') {
            return null;
        }

        return Action::make('openUpgrade')
            ->label(__('capell-admin::button.review_upgrades'))
            ->icon(Heroicon::OutlinedCloudArrowUp)
            ->color(UpgradePage::getNavigationBadgeColor() ?? 'warning')
            ->badge($badge)
            ->url(UpgradePage::getUrl());
    }
}
