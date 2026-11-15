<?php

declare(strict_types=1);

namespace Workbench\App\Support;

use Capell\Admin\Filament\Pages\Auth\AcceptInvitation;
use Capell\Admin\Support\Users\InvitationPasswordBroker;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;

final class InvitationScreenshotFixture
{
    private const string EMAIL = 'invited.editor@example.test';

    /** A signed link for a pending invitation, so the accept page renders its form instead of the expired-link state. */
    public static function acceptUrl(): string
    {
        $model = config()->string('auth.providers.users.model');
        $user = $model::query()->firstOrNew(['email' => self::EMAIL]);
        throw_unless($user instanceof Model && $user instanceof CanResetPassword, LogicException::class, 'The screenshot user model must support password resets.');

        if (! $user->exists) {
            $user->forceFill(['name' => 'Invited Editor', 'password' => Hash::make(Str::random(40))])->save();
        }

        InvitationPasswordBroker::revoke($user);

        return AcceptInvitation::acceptUrl(InvitationPasswordBroker::broker()->createToken($user), self::EMAIL);
    }
}
