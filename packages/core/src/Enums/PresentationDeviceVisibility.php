<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

use Capell\Core\Enums\Concerns\HasEnumOptions;
use Filament\Support\Contracts\HasLabel;

enum PresentationDeviceVisibility: string implements HasLabel
{
    use HasEnumOptions;

    case All = 'all';
    case MobileOnly = 'mobile_only';
    case DesktopOnly = 'desktop_only';
    case CustomRange = 'custom_range';

    public function getLabel(): string
    {
        return match ($this) {
            self::All => (string) __('capell::generic.all_devices'),
            self::MobileOnly => (string) __('capell::generic.mobile_only'),
            self::DesktopOnly => (string) __('capell::generic.presentation_device_desktop_only'),
            self::CustomRange => (string) __('capell::generic.presentation_device_custom_range'),
        };
    }
}
