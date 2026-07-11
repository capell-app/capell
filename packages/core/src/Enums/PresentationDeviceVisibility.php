<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum PresentationDeviceVisibility: string
{
    case All = 'all';
    case MobileOnly = 'mobile_only';
    case DesktopOnly = 'desktop_only';
    case CustomRange = 'custom_range';
}
