<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Blueprints\Widgets;

use Capell\Admin\Data\MessageData;
use Capell\Admin\Enums\AlertTypeEnum;
use Capell\Admin\Filament\Widgets\ResourceAlertsFilamentWidget;
use Capell\Core\Support\CapellCoreHelper;
use Illuminate\Support\Collection;

class BlueprintsAlertsWidget extends ResourceAlertsFilamentWidget
{
    /**
     * @return Collection<string, MessageData>
     */
    protected function buildAlerts(): Collection
    {
        $alerts = collect();

        if (CapellCoreHelper::missingDefaultTypes()) {
            $alerts->put('blueprints', new MessageData(
                title: __('capell-admin::message.type_missing_heading'),
                message: __('capell-admin::message.type_missing_warning'),
                type: AlertTypeEnum::Warning,
                icon: 'heroicon-o-shield-exclamation',
            ));
        }

        return $alerts;
    }
}
