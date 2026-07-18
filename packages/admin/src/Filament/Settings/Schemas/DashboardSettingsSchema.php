<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Settings\Schemas;

use Capell\Admin\Actions\SyncDashboardFilamentWidgetSettingsAction;
use Capell\Admin\Contracts\DashboardSettingsContributor;
use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Enums\FilamentWidgetEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Components\Forms\DashboardFilamentWidgetSettings;
use Capell\Admin\Filament\Contracts\HasSchema;
use Exception;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use ReflectionMethod;

final class DashboardSettingsSchema implements HasSchema
{
    /**
     * Build the Filament form components for the Dashboard Settings page section.
     *
     * @return list<Component>
     */
    public static function make(Schema $schema): array
    {
        SyncDashboardFilamentWidgetSettingsAction::run(
            repairFullyDisabledDefaults: true,
            repairOverEnabledDefaults: true,
        );

        $contributed = self::allContributedKeys(DashboardEnum::Main);

        return [
            Section::make(__('capell-admin::form.dashboard_widget_grid'))
                ->columnSpanFull()
                ->description(__('capell-admin::form.dashboard_widget_grid_helper'))
                ->schema([
                    DashboardFilamentWidgetSettings::make()
                        ->hiddenLabel()
                        ->widgets($contributed),
                ]),
            Section::make(__('capell-admin::form.dashboard_widget_settings'))
                ->columnSpanFull()
                ->description(__('capell-admin::form.dashboard_widget_settings_helper'))
                ->schema([
                    TextInput::make('my_work_queue_limit')
                        ->label(__('capell-admin::form.my_work_queue_limit'))
                        ->helperText(__('capell-admin::form.my_work_queue_limit_helper'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(100),
                    TextInput::make('recently_published_limit')
                        ->label(__('capell-admin::form.recently_published_limit'))
                        ->helperText(__('capell-admin::form.recently_published_limit_helper'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(100),
                    TextInput::make('cache_health_refresh_interval_seconds')
                        ->label(__('capell-admin::form.cache_health_refresh_interval_seconds'))
                        ->helperText(__('capell-admin::form.cache_health_refresh_interval_seconds_helper'))
                        ->numeric()
                        ->minValue(5)
                        ->maxValue(3600)
                        ->suffix(__('capell-admin::form.seconds')),
                    TextInput::make('ai_orchestrator_spend_window_days')
                        ->label(__('capell-admin::form.ai_orchestrator_spend_window_days'))
                        ->helperText(__('capell-admin::form.ai_orchestrator_spend_window_days_helper'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(365)
                        ->suffix(__('capell-admin::form.days')),
                ])
                ->columns(2),
        ];
    }

    /**
     * Aggregate widget metadata declared by every contributor tagged on the container.
     *
     * @return list<array{key: string, label: string, group: string, description: string|null}>
     */
    public static function allContributedKeys(?DashboardEnum $dashboard = null): array
    {
        /** @var iterable<DashboardSettingsContributor> $contributors */
        $contributors = app()->tagged(DashboardSettingsContributor::TAG);

        $byKey = [];
        foreach ($contributors as $contributor) {
            foreach ($contributor->settingsKeys() as $entry) {
                $existingDescription = $byKey[$entry['key']]['description'] ?? null;
                $byKey[$entry['key']] = $entry;  // last write wins — contributors loaded later win, which matches Laravel's container tag priority

                if (! isset($entry['description']) && is_string($existingDescription)) {
                    $byKey[$entry['key']]['description'] = $existingDescription;
                }
            }
        }

        foreach (CapellAdmin::getOverviewStatSettings() as $entry) {
            $byKey[$entry['key']] = $entry;
        }

        $widgetClassesByKey = self::widgetClassesBySettingsKey($dashboard);
        $dashboardGroup = $dashboard instanceof DashboardEnum
            ? str($dashboard->value)->replace('_', ' ')->headline()->toString()
            : 'Dashboard';

        foreach ($widgetClassesByKey as $settingsKey => $widgetClass) {
            $byKey[$settingsKey] ??= [
                'key' => $settingsKey,
                'label' => self::labelFor($widgetClass),
                'group' => $dashboardGroup,
            ];
        }

        $entries = collect($byKey);

        if ($dashboard instanceof DashboardEnum) {
            $entries = $entries->filter(fn (array $entry): bool => isset($widgetClassesByKey[$entry['key']]));
        }

        return array_values($entries
            ->map(fn (array $entry): array => [
                'key' => $entry['key'],
                'label' => $entry['label'],
                'group' => $entry['group'],
                'description' => self::descriptionFor([
                    'key' => $entry['key'],
                    'label' => $entry['label'],
                    'group' => $entry['group'],
                    'description' => $entry['description'] ?? null,
                ], $widgetClassesByKey[$entry['key']] ?? null),
            ])
            ->values()
            ->all());
    }

    /**
     * @return array<string, class-string>
     */
    private static function widgetClassesBySettingsKey(?DashboardEnum $dashboard = null): array
    {
        $dashboards = $dashboard instanceof DashboardEnum
            ? [$dashboard]
            : DashboardEnum::cases();

        $enumWidgets = ! $dashboard instanceof DashboardEnum || $dashboard === DashboardEnum::Main
            ? collect(FilamentWidgetEnum::cases())->map(fn (FilamentWidgetEnum $widgetEnum): string => $widgetEnum->value)
            : collect();

        return collect()
            ->merge($enumWidgets)
            ->merge(collect($dashboards)->flatMap(fn (DashboardEnum $dashboard): array => CapellAdmin::getDashboardFilamentWidgets($dashboard)))
            ->filter(fn (string $widgetClass): bool => class_exists($widgetClass) && method_exists($widgetClass, 'settingsKey'))
            ->mapWithKeys(function (string $widgetClass): array {
                try {
                    $settingsKey = $widgetClass::settingsKey();
                } catch (Exception) {
                    return [];
                }

                return is_string($settingsKey) && $settingsKey !== ''
                    ? [$settingsKey => $widgetClass]
                    : [];
            })
            ->all();
    }

    /**
     * @param  class-string  $widgetClass
     */
    private static function labelFor(string $widgetClass): string
    {
        if (method_exists($widgetClass, 'getHeading')) {
            try {
                $heading = (new $widgetClass)->getHeading();

                if (is_string($heading) && $heading !== '') {
                    return $heading;
                }
            } catch (Exception) {
                // Widget headings may require an initialized Filament request; use the class-name fallback below.
            }
        }

        return str(class_basename($widgetClass))->replace('Widget', '')->headline()->toString();
    }

    /**
     * @param  array{key: string, label: string, group: string, description?: string|null}  $entry
     * @param  class-string|null  $widgetClass
     */
    private static function descriptionFor(array $entry, ?string $widgetClass): ?string
    {
        if (is_string($entry['description'] ?? null) && $entry['description'] !== '') {
            return $entry['description'];
        }

        if ($widgetClass === null || ! method_exists($widgetClass, 'getDescription')) {
            return null;
        }

        $method = new ReflectionMethod($widgetClass, 'getDescription');
        if (! $method->isStatic()) {
            return null;
        }

        try {
            $description = $widgetClass::getDescription();
        } catch (Exception) {
            return null;
        }

        return is_string($description) && $description !== '' ? $description : null;
    }
}
