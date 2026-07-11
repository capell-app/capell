<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Themes\Widgets;

use Capell\Admin\Data\MessageData;
use Capell\Admin\Enums\AlertTypeEnum;
use Capell\Admin\Filament\Widgets\ResourceAlertsFilamentWidget;
use Capell\Core\Support\CapellCoreHelper;
use Illuminate\Support\Collection;

class ThemesAlertsWidget extends ResourceAlertsFilamentWidget
{
    /**
     * @return Collection<string, MessageData>
     */
    protected function buildAlerts(): Collection
    {
        $alerts = collect();

        if (! CapellCoreHelper::hasFoundationTheme()) {
            $alerts->put('theme', new MessageData(
                title: __('capell-admin::message.theme_missing_heading'),
                message: __('capell-admin::message.theme_missing_warning'),
                type: CapellCoreHelper::hasSiteType() ? AlertTypeEnum::Warning : AlertTypeEnum::Danger,
                icon: 'heroicon-o-shield-exclamation',
            ));
        }

        return $alerts;
    }
}
