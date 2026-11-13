<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Users;

use Capell\Core\Support\TableName;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use SensitiveParameter;

/**
 * The password broker behind invitation links.
 *
 * It shares the users provider with the normal broker but keeps its own token
 * table, so reset tokens and invitation tokens can never redeem each other.
 * Tokens last seven days by default; auth.passwords.capell_invitations.expire
 * (minutes) is the single source for every lifetime shown or enforced.
 */
final class InvitationPasswordBroker
{
    public const string NAME = 'capell_invitations';

    public const int DEFAULT_EXPIRES_IN_MINUTES = 60 * 24 * 7;

    public static function broker(): PasswordBroker
    {
        self::ensureConfigured();

        return Password::broker(self::NAME);
    }

    public static function expiresInMinutes(): int
    {
        self::ensureConfigured();

        $minutes = config('auth.passwords.' . self::NAME . '.expire');

        return is_numeric($minutes) && (int) $minutes > 0 ? (int) $minutes : self::DEFAULT_EXPIRES_IN_MINUTES;
    }

    /** Whole days, rounded up, for copy that tells people how long a link lasts. */
    public static function expiresInDays(): int
    {
        return max(1, (int) ceil(self::expiresInMinutes() / (60 * 24)));
    }

    public static function table(): string
    {
        self::ensureConfigured();

        return (string) config('auth.passwords.' . self::NAME . '.table');
    }

    /**
     * True while the user holds an invitation and has not yet signed in, reset
     * their password or had one saved for them: RevokePendingInvitation deletes
     * the row on each of those.
     */
    public static function hasPendingInvitation(CanResetPassword $user): bool
    {
        if (! Schema::hasTable(self::table())) {
            return false;
        }

        return DB::table(self::table())
            ->where('email', $user->getEmailForPasswordReset())
            ->exists();
    }

    /** Ends any pending invitation for this user so its link stops working. */
    public static function revoke(CanResetPassword $user): void
    {
        $table = self::table();

        if (! Schema::hasTable($table)) {
            return;
        }

        DB::table($table)->where('email', $user->getEmailForPasswordReset())->delete();
    }

    /** True when the token still redeems an invitation for this address. */
    public static function isValid(string $email, #[SensitiveParameter] string $token): bool
    {
        if ($email === '' || $token === '') {
            return false;
        }

        $broker = self::broker();
        $user = $broker->getUser(['email' => $email]);

        return $user instanceof CanResetPassword && $broker->tokenExists($user, $token);
    }

    public static function ensureConfigured(): void
    {
        $defaultBroker = (string) config('auth.defaults.passwords', 'users');
        $base = config('auth.passwords.' . $defaultBroker);
        $base = is_array($base) ? $base : [];

        $host = config('auth.passwords.' . self::NAME);
        $host = is_array($host) ? $host : [];

        // A host may override any part; anything it leaves out keeps Capell's
        // default, so a partial override can never share the reset-token table.
        config(['auth.passwords.' . self::NAME => [
            'provider' => $base['provider'] ?? 'users',
            'table' => TableName::resolve('admin_user_invitation_tokens'),
            'expire' => self::DEFAULT_EXPIRES_IN_MINUTES,
            'throttle' => $base['throttle'] ?? 60,
            ...array_filter($host, static fn (mixed $value): bool => $value !== null && $value !== ''),
        ]]);
    }
}
