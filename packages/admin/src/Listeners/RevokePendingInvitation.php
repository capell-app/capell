<?php

declare(strict_types=1);

namespace Capell\Admin\Listeners;

use Capell\Admin\Support\Users\InvitationPasswordBroker;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Model;

/**
 * An invitation only exists to let someone choose their first password. Once
 * they sign in, reset their password, or anyone saves a password for them, the
 * invitation link must stop working, or a leaked link could still take over
 * the account.
 */
final class RevokePendingInvitation
{
    public function handleLogin(Login $event): void
    {
        $this->revoke($event->user);
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        $this->revoke($event->user);
    }

    /**
     * @param  array<int, mixed>  $payload
     */
    public function handleSaved(string $event, array $payload): void
    {
        $userModel = config('auth.providers.users.model');
        $user = $payload[0] ?? null;

        if (! is_string($userModel) || ! $user instanceof $userModel || ! $user instanceof Model) {
            return;
        }

        // An insert records no changes, so only a later password save revokes.
        if ($user->wasChanged('password')) {
            $this->revoke($user);
        }
    }

    private function revoke(mixed $user): void
    {
        if ($user instanceof CanResetPassword) {
            InvitationPasswordBroker::revoke($user);
        }
    }
}
