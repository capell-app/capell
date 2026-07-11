<?php

declare(strict_types=1);

use Capell\Admin\Filament\Widgets\ResourceAlertsFilamentWidget;

// Enumerate all 7 concrete resource alert widgets explicitly.
// Note: pest-plugin-arch v3/v4 does not support toHaveName() with regex
// for filtering, so we verify each Resources sub-namespace that ends in
// AlertsWidget extends the shared base.

arch('page resource alert widgets extend ResourceAlertsFilamentWidget')
    ->expect('Capell\Admin\Filament\Resources\Pages\Widgets')
    ->classes()
    ->toExtend(ResourceAlertsFilamentWidget::class);

arch('site resource alert widgets extend ResourceAlertsFilamentWidget')
    ->expect('Capell\Admin\Filament\Resources\Sites\Widgets')
    ->classes()
    ->toExtend(ResourceAlertsFilamentWidget::class);

arch('type resource alert widgets extend ResourceAlertsFilamentWidget')
    ->expect('Capell\Admin\Filament\Resources\Blueprints\Widgets')
    ->classes()
    ->toExtend(ResourceAlertsFilamentWidget::class);

arch('language resource alert widgets extend ResourceAlertsFilamentWidget')
    ->expect('Capell\Admin\Filament\Resources\Languages\Widgets')
    ->classes()
    ->toExtend(ResourceAlertsFilamentWidget::class);

arch('theme resource alert widgets extend ResourceAlertsFilamentWidget')
    ->expect('Capell\Admin\Filament\Resources\Themes\Widgets')
    ->classes()
    ->toExtend(ResourceAlertsFilamentWidget::class);
