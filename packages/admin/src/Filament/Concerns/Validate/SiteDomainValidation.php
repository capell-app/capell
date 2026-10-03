<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns\Validate;

use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Core\Actions\SiteDomains\FindSiteDomainConflictAction;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\Permissions\SiteAccess;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

trait SiteDomainValidation
{
    /**
     * @param  array<string, string|null>  $urlParts
     */
    public static function validateExists(array $urlParts, ?SiteDomain $record = null): bool
    {
        $matchSite = FindSiteDomainConflictAction::run($urlParts, $record);

        if ($matchSite !== null) {
            Notification::make('site_domain_unique')
                ->warning()
                ->title(__('capell-admin::message.site_domain_not_unique', ['name' => SiteAccess::current()->can($matchSite->site) ? $matchSite->site->name : __('capell-admin::generic.site')]))
                ->body($matchSite->full_url)
                ->actions(SiteAccess::current()->can($matchSite->site) ? [
                    Action::make('editSite')
                        ->label(__('capell-admin::button.edit_site'))
                        ->button()
                        ->icon('heroicon-o-pencil-square')
                        ->url(AdminSurfaceLookup::resource(ResourceEnum::Site)::getUrl('edit', ['record' => $matchSite->site_id])),
                ] : [])
                ->send();

            return false;
        }

        return true;
    }
}
