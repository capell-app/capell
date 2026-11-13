<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Users;

use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Support\Facades\Password;

/**
 * The password broker behind invitation links.
 *
 * It shares the users provider and reset-token table with the normal broker
 * but keeps tokens valid for seven days, so a client has time to accept. A
 * host can override it by defining auth.passwords.capell_invitations.
 */
final class InvitationPasswordBroker
{
    public const string NAME = 'capell_invitations';

    public const int EXPIRES_IN_MINUTES = 60 * 24 * 7;

    public static function broker(): PasswordBroker
    {
        self::ensureConfigured();

        return Password::broker(self::NAME);
    }

    public static function ensureConfigured(): void
    {
        if (is_array(config('auth.passwords.' . self::NAME))) {
            return;
        }

        $defaultBroker = (string) config('auth.defaults.passwords', 'users');
        $base = config('auth.passwords.' . $defaultBroker);
        $base = is_array($base) ? $base : [];

        config(['auth.passwords.' . self::NAME => [
            'provider' => $base['provider'] ?? 'users',
            'table' => $base['table'] ?? 'password_reset_tokens',
            'expire' => self::EXPIRES_IN_MINUTES,
            'throttle' => $base['throttle'] ?? 60,
        ]]);
    }
}
