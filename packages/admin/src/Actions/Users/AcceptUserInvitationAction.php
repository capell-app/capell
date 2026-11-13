<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Users;

use Capell\Admin\Support\Users\InvitationPasswordBroker;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use SensitiveParameter;

/**
 * Sets the invitee's chosen password. The broker deletes the token on success,
 * so an invitation link works once.
 */
final class AcceptUserInvitationAction
{
    use AsFake;
    use AsObject;

    /**
     * @return (Authenticatable&CanResetPassword&Model)|null The user on success, null when the link is invalid or has expired.
     */
    public function handle(
        string $email,
        #[SensitiveParameter]
        string $token,
        #[SensitiveParameter]
        string $password,
    ): ?Model {
        $acceptedUser = null;

        $status = InvitationPasswordBroker::broker()->reset(
            [
                'email' => $email,
                'token' => $token,
                'password' => $password,
            ],
            function (Authenticatable&CanResetPassword&Model $user, string $password) use (&$acceptedUser): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ]);

                // Following the emailed link proves the address.
                if (array_key_exists('email_verified_at', $user->getAttributes()) && $user->getAttribute('email_verified_at') === null) {
                    $user->forceFill(['email_verified_at' => now()]);
                }

                $user->save();

                event(new PasswordReset($user));

                $acceptedUser = $user;
            },
        );

        return $status === Password::PASSWORD_RESET ? $acceptedUser : null;
    }
}
