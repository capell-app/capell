<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Users;

use Capell\Admin\Enums\RolePreset;
use Capell\Admin\Filament\Pages\Auth\AcceptInvitation;
use Capell\Admin\Notifications\UserInvitationNotification;
use Capell\Admin\Support\Users\InvitationPasswordBroker;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Emails an existing user a fresh set-password link, replacing any earlier
 * invitation token. Used when an invitation expired or went astray.
 */
final class ResendUserInvitationAction
{
    use AsFake;
    use AsObject;

    public static function canResend(Authenticatable $user, Authenticatable $actor): bool
    {
        if ($user->is($actor)) {
            return false;
        }

        return InviteUserAction::canInvite($actor) && Gate::forUser($actor)->allows('update', $user);
    }

    public function handle(Authenticatable $user, Authenticatable $actor): void
    {
        throw_unless(self::canResend($user, $actor), AuthorizationException::class);

        $token = InvitationPasswordBroker::broker()->createToken($user);
        $email = (string) $user->getAttribute('email');

        $user->notify(new UserInvitationNotification(
            acceptUrl: AcceptInvitation::acceptUrl($token, $email),
            inviterName: (string) $actor->getAttribute('name'),
            siteName: (string) config('app.name'),
            preset: $this->presetFor($user),
        ));
    }

    private function presetFor(Authenticatable $user): ?RolePreset
    {
        if (! method_exists($user, 'getRoleNames')) {
            return null;
        }

        foreach ($user->getRoleNames() as $roleName) {
            $preset = RolePreset::forRoleName((string) $roleName);

            if ($preset instanceof RolePreset) {
                return $preset;
            }
        }

        return null;
    }
}
