<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Presentation;

use BackedEnum;
use Capell\Core\Data\Presentation\PresentationSettingsData;
use Capell\Core\Enums\PresentationAlignment;
use Capell\Core\Enums\PresentationConnectionRequirement;
use Capell\Core\Enums\PresentationDeliveryMode;
use Capell\Core\Enums\PresentationDeviceVisibility;
use Capell\Core\Enums\PresentationLoadingStrategy;
use Capell\Core\Enums\PresentationWidthMode;
use Capell\Core\Support\Presentation\PresentationPresetRegistry;
use Illuminate\Support\Arr;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ResolvePresentationSettingsAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<string, mixed>  $instanceSettings
     * @param  array<string, mixed>  $typeDefaults
     */
    public function handle(array $instanceSettings = [], array $typeDefaults = []): PresentationSettingsData
    {
        $presetKey = $this->stringValue($instanceSettings['presentation_preset'] ?? null)
            ?? $this->stringValue($typeDefaults['presentation_preset'] ?? null);
        $preset = resolve(PresentationPresetRegistry::class)->get($presetKey);

        $settings = array_replace(
            $preset->settings ?? [],
            $typeDefaults,
            $instanceSettings,
        );

        $customWidth = $this->customWidth($settings['custom_width'] ?? null);
        $widthMode = $this->enumValue(PresentationWidthMode::class, $settings['width_mode'] ?? null, PresentationWidthMode::Inherit);

        if ($widthMode === PresentationWidthMode::Custom && $customWidth === null) {
            $widthMode = PresentationWidthMode::Inherit;
        }

        return new PresentationSettingsData(
            deliveryMode: $this->enumValue(PresentationDeliveryMode::class, $settings['delivery_mode'] ?? null, PresentationDeliveryMode::ServerRendered),
            deviceVisibility: $this->enumValue(PresentationDeviceVisibility::class, $settings['device_visibility'] ?? null, PresentationDeviceVisibility::All),
            connectionRequirement: $this->enumValue(PresentationConnectionRequirement::class, $settings['connection_requirement'] ?? null, PresentationConnectionRequirement::Any),
            loadingStrategy: $this->enumValue(PresentationLoadingStrategy::class, $settings['loading_strategy'] ?? null, PresentationLoadingStrategy::Eager),
            widthMode: $widthMode,
            alignment: $this->enumValue(PresentationAlignment::class, $settings['alignment'] ?? null, PresentationAlignment::Stretch),
            presentationPreset: $presetKey,
            minViewportWidth: $this->viewportWidth($settings['min_viewport_width'] ?? null),
            maxViewportWidth: $this->viewportWidth($settings['max_viewport_width'] ?? null),
            customWidth: $customWidth,
        );
    }

    /**
     * @param  array<string, mixed>  $blockData
     * @param  array<string, mixed>  $typeDefaults
     */
    public function fromWidgetBlockData(array $blockData, array $typeDefaults = []): PresentationSettingsData
    {
        $presentation = Arr::get($blockData, 'data.__capell.presentation');

        return $this->handle(is_array($presentation) ? $presentation : [], $typeDefaults);
    }

    /**
     * @template TEnum of \BackedEnum
     *
     * @param  class-string<TEnum>  $enum
     * @param  TEnum  $default
     * @return TEnum
     */
    private function enumValue(string $enum, mixed $value, BackedEnum $default): BackedEnum
    {
        if (! is_string($value) || $value === '') {
            return $default;
        }

        return $enum::tryFrom($value) ?? $default;
    }

    private function viewportWidth(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(0, min(4096, (int) $value));
    }

    private function customWidth(mixed $value): ?string
    {
        return PresentationSettingsData::normalizeCustomWidth($value);
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
