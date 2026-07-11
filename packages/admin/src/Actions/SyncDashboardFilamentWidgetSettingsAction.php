<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Admin\Contracts\DashboardSettingsContributor;
use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Enums\FilamentWidgetEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Settings\AdminSettings;
use Exception;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsObject;

final class SyncDashboardFilamentWidgetSettingsAction
{
    use AsObject;

    /** @var list<string> */
    private const array REMOVED_WIDGET_KEYS = [
        'capell_info',
    ];

    public function handle(
        bool $repairFullyDisabledDefaults = false,
        bool $forceEnableDefaults = false,
        bool $repairOverEnabledDefaults = false,
    ): AdminSettings {
        PersistMissingSettingsDefaultsAction::run(AdminSettings::class);

        $settings = AdminSettings::instance();
        $enabledWidgets = $settings->enabled_widgets;
        $knownKeys = $this->knownWidgetKeys();
        $defaultEnabledKeys = $this->defaultEnabledWidgetKeys();

        if ($forceEnableDefaults || ($repairFullyDisabledDefaults && $this->allKnownDefaultsAreDisabled($enabledWidgets, $defaultEnabledKeys))) {
            foreach ($defaultEnabledKeys as $defaultKey) {
                $enabledWidgets[$defaultKey] = true;
            }
        }

        if ($repairOverEnabledDefaults && $this->allOptionalWidgetsAreEnabled($enabledWidgets, $knownKeys, $defaultEnabledKeys)) {
            foreach (array_diff($knownKeys, $defaultEnabledKeys) as $optionalKey) {
                $enabledWidgets[$optionalKey] = false;
            }
        }

        foreach ($this->promotedDefaultWidgetKeys() as $promotedDefaultKey) {
            if (in_array($promotedDefaultKey, $defaultEnabledKeys, true)) {
                $enabledWidgets[$promotedDefaultKey] = true;
            }
        }

        foreach ($knownKeys as $knownKey) {
            if (! array_key_exists($knownKey, $enabledWidgets)) {
                $enabledWidgets[$knownKey] = in_array($knownKey, $defaultEnabledKeys, true);
            }
        }

        foreach (self::REMOVED_WIDGET_KEYS as $removedWidgetKey) {
            unset($enabledWidgets[$removedWidgetKey]);
        }

        $hasChanged = false;

        if ($enabledWidgets !== $settings->enabled_widgets) {
            $settings->enabled_widgets = $enabledWidgets;
            $hasChanged = true;
        }

        $widgetOrder = array_replace(AdminSettings::defaultWidgetOrder(), $settings->widget_order);
        foreach (self::REMOVED_WIDGET_KEYS as $removedWidgetKey) {
            unset($widgetOrder[$removedWidgetKey]);
        }

        if ($widgetOrder !== $settings->widget_order) {
            $settings->widget_order = $widgetOrder;
            $hasChanged = true;
        }

        if ($hasChanged) {
            $settings->save();
        }

        return $settings->refresh();
    }

    /**
     * @return list<string>
     */
    private function knownWidgetKeys(): array
    {
        return array_values(collect()
            ->merge($this->contributedWidgetKeys())
            ->merge(CapellAdmin::getOverviewStatKeys())
            ->merge($this->registeredDashboardFilamentWidgetKeys())
            ->merge($this->enumWidgetKeys())
            ->filter(fn (string $settingsKey): bool => $settingsKey !== '')
            ->unique()
            ->values()
            ->all());
    }

    /** @return list<string> */
    private function promotedDefaultWidgetKeys(): array
    {
        return [
            'extensions.stats',
        ];
    }

    /**
     * @return list<string>
     */
    private function defaultEnabledWidgetKeys(): array
    {
        return array_values(collect(array_keys(AdminSettings::defaultWidgetOrder()))
            ->filter(fn (string $settingsKey): bool => $settingsKey !== '')
            ->unique()
            ->values()
            ->all());
    }

    /**
     * @return Collection<int, string>
     */
    private function contributedWidgetKeys(): Collection
    {
        /** @var iterable<DashboardSettingsContributor> $contributors */
        $contributors = app()->tagged(DashboardSettingsContributor::TAG);

        return collect($contributors)
            ->flatMap(fn (DashboardSettingsContributor $contributor): array => $contributor->settingsKeys())
            ->pluck('key')
            ->filter(fn (mixed $settingsKey): bool => is_string($settingsKey));
    }

    /**
     * @return Collection<int, string>
     */
    private function registeredDashboardFilamentWidgetKeys(): Collection
    {
        return collect(DashboardEnum::cases())
            ->flatMap(fn (DashboardEnum $dashboard): array => CapellAdmin::getDashboardFilamentWidgets($dashboard))
            ->map(fn (string $widgetClass): string => $this->settingsKeyFor($widgetClass));
    }

    /**
     * @return Collection<int, string>
     */
    private function enumWidgetKeys(): Collection
    {
        return collect(FilamentWidgetEnum::cases())
            ->map(fn (FilamentWidgetEnum $widgetEnum): string => $this->settingsKeyFor($widgetEnum->value));
    }

    private function settingsKeyFor(string $widgetClass): string
    {
        if (! class_exists($widgetClass) || ! method_exists($widgetClass, 'settingsKey')) {
            return '';
        }

        try {
            $settingsKey = $widgetClass::settingsKey();
        } catch (Exception) {
            return '';
        }

        return is_string($settingsKey) ? $settingsKey : '';
    }

    /**
     * @param  array<string, bool>  $enabledWidgets
     * @param  list<string>  $defaultKeys
     */
    private function allKnownDefaultsAreDisabled(array $enabledWidgets, array $defaultKeys): bool
    {
        $knownValues = array_intersect_key($enabledWidgets, array_flip($defaultKeys));

        if ($knownValues === []) {
            return false;
        }

        return collect($knownValues)->every(fn (bool $enabled): bool => $enabled === false);
    }

    /**
     * @param  array<string, bool>  $enabledWidgets
     * @param  list<string>  $knownKeys
     * @param  list<string>  $defaultEnabledKeys
     */
    private function allOptionalWidgetsAreEnabled(array $enabledWidgets, array $knownKeys, array $defaultEnabledKeys): bool
    {
        $optionalKeys = array_values(array_diff($knownKeys, $defaultEnabledKeys));

        if ($optionalKeys === []) {
            return false;
        }

        $optionalValues = array_intersect_key($enabledWidgets, array_flip($optionalKeys));

        return $optionalValues !== []
            && collect($optionalValues)->every(fn (bool $enabled): bool => $enabled);
    }
}
