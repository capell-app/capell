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

    public function handleUpdated(mixed $user): void
    {
        if ($user instanceof Model && $user->wasChanged('password')) {
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
