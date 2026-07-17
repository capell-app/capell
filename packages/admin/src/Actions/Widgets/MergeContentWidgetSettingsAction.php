<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Widgets;

use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class MergeContentWidgetSettingsAction
{
    use AsFake;
    use AsObject;

    /** @var list<string> */
    private const array OWNED_SETTINGS = ['interactions', 'presentation', 'resources'];

    /**
     * @param  array<string, mixed>  $widgetData
     * @param  array<string, mixed>  $submittedSettings
     * @return array<string, mixed>
     */
    public function handle(array $widgetData, array $submittedSettings): array
    {
        $capellState = is_array($widgetData['__capell'] ?? null)
            ? $widgetData['__capell']
            : [];

        foreach (self::OWNED_SETTINGS as $settingsKey) {
            unset($capellState[$settingsKey]);

            $settings = $submittedSettings[$settingsKey] ?? null;

            if (! is_array($settings)) {
                continue;
            }

            $settings = $this->stripBlankValues($settings);

            if ($settings !== []) {
                $capellState[$settingsKey] = $settings;
            }
        }

        if ($capellState === []) {
            unset($widgetData['__capell']);

            return $widgetData;
        }

        $widgetData['__capell'] = $capellState;

        return $widgetData;
    }

    /**
     * @param  array<int|string, mixed>  $state
     * @return array<int|string, mixed>
     */
    private function stripBlankValues(array $state): array
    {
        foreach ($state as $key => $value) {
            if (is_array($value)) {
                $value = $this->stripBlankValues($value);
            }

            if (blank($value)) {
                unset($state[$key]);

                continue;
            }

            $state[$key] = $value;
        }

        return $state;
    }
}
