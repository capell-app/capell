<?php

declare(strict_types=1);

namespace Capell\Admin\Http\Controllers;

use Capell\Admin\Actions\Users\ResolveAdminLocaleForUserAction;
use Capell\Admin\Actions\Users\SetUserPreferredAdminLanguageAction;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class UpdateAuthenticatedAdminLanguageController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless(
            $user instanceof Model
            && $user instanceof FilamentUser
            && $user->canAccessPanel(Filament::getPanel('admin')),
            403,
        );

        try {
            SetUserPreferredAdminLanguageAction::run($user, $request->input('preferred_admin_language_id'));
        } catch (InvalidArgumentException $invalidArgumentException) {
            throw ValidationException::withMessages([
                'preferred_admin_language_id' => $invalidArgumentException->getMessage(),
            ]);
        }

        $locale = ResolveAdminLocaleForUserAction::run($user->fresh());
        app()->setLocale($locale);
        resolve(Translator::class)->setLocale($locale);

        Notification::make()
            ->title(__('capell-admin::notification.admin_language_updated'))
            ->success()
            ->send();

        return back();
    }
}
