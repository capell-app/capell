<?php

declare(strict_types=1);

namespace Capell\Marketplace\Enums;

use Filament\Support\Contracts\HasLabel;

enum MarketplaceExtensionCapability: string implements HasLabel
{
    case FilamentPanel = 'filament_panel';
    case LivewireComponents = 'livewire_components';
    case LaravelRoutes = 'laravel_routes';
    case Migrations = 'migrations';
    case Settings = 'settings';
    case DashboardFilamentWidgets = 'dashboard_widgets';
    case StaticExport = 'static_export';
    case FormBuilder = 'form-builder';
    case Search = 'search';

    public function getLabel(): string
    {
        return (string) __('capell-marketplace::marketplace.capabilities.' . $this->value);
    }
}
