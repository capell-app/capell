<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Pages\Widgets;

use Capell\Admin\Data\MessageData;
use Capell\Admin\Enums\AlertTypeEnum;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Widgets\ResourceAlertsFilamentWidget;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Core\Models\Site;
use Filament\Actions\Action;
use Illuminate\Support\Collection;

class ListPageAlertsWidget extends ResourceAlertsFilamentWidget
{
    /**
     * @return Collection<string, MessageData>
     */
    protected function buildAlerts(): Collection
    {
        $alerts = collect();

        /** @var class-string<Site> $model */
        $model = Site::class;

        if ($model::totalSites() === 0) {
            $alerts->put('site', new MessageData(
                message: __('capell-admin::message.site_missing_warning'),
                type: AlertTypeEnum::Warning,
                icon: 'heroicon-o-shield-exclamation',
                action: Action::make('createSite')
                    ->label(__('capell-admin::button.create_site'))
                    ->url(AdminSurfaceLookup::resource(ResourceEnum::Site)::getUrl('create'))
                    ->button(),
            ));
        }

        return $alerts;
    }
}
