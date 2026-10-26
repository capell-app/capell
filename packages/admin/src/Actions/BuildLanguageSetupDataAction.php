<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Admin\Actions\Users\ResolveAdminLocaleForUserAction;
use Capell\Admin\Data\Settings\LanguageSetupData;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Http\Middleware\SetAdminLocale;
use Capell\Admin\Support\AdminSurfaceLookup;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildLanguageSetupDataAction
{
    use AsFake;
    use AsObject;

    public function handle(): LanguageSetupData
    {
        $user = auth()->user();
        $users = AdminSurfaceLookup::resourceIfRegistered(ResourceEnum::User);
        $sites = AdminSurfaceLookup::resourceIfRegistered(ResourceEnum::Site);

        return new LanguageSetupData(
            applicationLocale: (string) request()->attributes->get(SetAdminLocale::APPLICATION_LOCALE_ATTRIBUTE, config('app.locale', 'en')),
            adminLocale: ResolveAdminLocaleForUserAction::run($user instanceof Model ? $user : null),
            userUrl: $user instanceof Model && $users !== null && $users::canEdit($user)
                ? $users::getUrl('edit', ['record' => $user]) : null,
            sitesUrl: $sites !== null && $sites::canViewAny() ? $sites::getUrl('index') : null,
        );
    }
}
