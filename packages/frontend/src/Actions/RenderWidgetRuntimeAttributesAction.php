<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Data\Presentation\PresentationSettingsData;
use Capell\Core\Enums\PresentationAlignment;
use Capell\Core\Enums\PresentationDeviceVisibility;
use Capell\Core\Enums\PresentationWidthMode;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class RenderWidgetRuntimeAttributesAction
{
    use AsFake;
    use AsObject;

    /**
     * @return array{class: string, style: string, data: array<string, mixed>}
     */
    public function handle(PresentationSettingsData $settings): array
    {
        $classes = [
            'capell-widget-runtime',
            'capell-widget-runtime--width-' . $settings->widthMode->value,
            'capell-widget-runtime--align-' . $settings->alignment->value,
            'capell-widget-runtime--visibility-' . $settings->deviceVisibility->value,
        ];

        return [
            'class' => implode(' ', $classes),
            'style' => $this->style($settings),
            'data' => $settings->publicRuntimePayload(),
        ];
    }

    private function style(PresentationSettingsData $settings): string
    {
        $styles = [];
        $customWidth = $settings->publicCustomWidth();

        if ($customWidth !== null) {
            $styles[] = 'max-width: ' . $customWidth;
        }

        if ($settings->alignment === PresentationAlignment::Center) {
            $styles[] = 'margin-left: auto';
            $styles[] = 'margin-right: auto';
        }

        if ($settings->alignment === PresentationAlignment::Right) {
            $styles[] = 'margin-left: auto';
        }

        if ($settings->widthMode === PresentationWidthMode::Full) {
            $styles[] = 'width: 100%';
        }

        if ($settings->deviceVisibility === PresentationDeviceVisibility::MobileOnly) {
            $styles[] = '--capell-widget-max-width: 767px';
        }

        return implode('; ', $styles);
    }
}
