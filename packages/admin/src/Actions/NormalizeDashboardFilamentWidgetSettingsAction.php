<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Filament\Settings\Schemas\DashboardSettingsSchema;
use Capell\Admin\Settings\AdminSettings;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class NormalizeDashboardFilamentWidgetSettingsAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function handle(array $settings, ?DashboardEnum $dashboard = null): array
    {
        $layout = $settings['widget_layout'] ?? null;

        if (is_string($layout)) {
            $decodedLayout = json_decode($layout, true);
            $layout = is_array($decodedLayout) ? $decodedLayout : null;
        }

        if (! is_array($layout)) {
            return $settings;
        }

        $adminSettings = AdminSettings::instance();
        $enabledWidgets = $dashboard instanceof DashboardEnum ? $adminSettings->enabled_widgets : [];
        $widgetOrder = $dashboard instanceof DashboardEnum ? $adminSettings->widget_order : [];

        $configuredKeys = collect(DashboardSettingsSchema::allContributedKeys($dashboard))
            ->pluck('key')
            ->filter(fn (mixed $settingsKey): bool => is_string($settingsKey) && $settingsKey !== '')
            ->values()
            ->all();

        foreach ($configuredKeys as $settingsKey) {
            $enabledWidgets[$settingsKey] = false;
        }

        foreach (array_values($layout) as $layoutIndex => $layoutItem) {
            if (! is_array($layoutItem)) {
                continue;
            }

            if (! isset($layoutItem['key'])) {
                continue;
            }

            if (! is_string($layoutItem['key'])) {
                continue;
            }

            if ($layoutItem['key'] === '') {
                continue;
            }

            $settingsKey = $layoutItem['key'];
            $enabledWidgets[$settingsKey] = ($layoutItem['enabled'] ?? true) === true;
            $widgetOrder[$settingsKey] = $this->normaliseWidgetOrder($layoutItem['order'] ?? $layoutIndex + 1);
        }

        $settings['enabled_widgets'] = $enabledWidgets;
        $settings['widget_order'] = $widgetOrder;
        unset($settings['widget_layout']);

        return $settings;
    }

    private function normaliseWidgetOrder(mixed $order): int
    {
        return max(0, (int) $order);
    }
}
