<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Auth;

use Capell\Admin\Enums\AdminLoginLinkRefusal;
use Capell\Admin\Support\Auth\AdminLoginLinks;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use SensitiveParameter;

/**
 * Checks a sign-in link and, when asked to, uses it up. A link is good only
 * while it is the newest one issued, has not expired, matches the password
 * the person had when it was sent and the panel still admits them. Using it
 * deletes the row in a single conditional delete, so two requests racing with
 * the same link cannot both sign in.
 */
final class RedeemAdminLoginLinkAction
{
    use AsFake;
    use AsObject;

    /**
     * @return Authenticatable|AdminLoginLinkRefusal|null The person to sign in, why they may not, or null when the link does not work.
     */
    public function handle(
        string $userId,
        #[SensitiveParameter]
        string $token,
        Panel $panel,
        bool $consume = true,
    ): Authenticatable|AdminLoginLinkRefusal|null {
        if (! AdminLoginLinks::enabled() || $userId === '' || $token === '') {
            return null;
        }

        $table = DB::table(AdminLoginLinks::table());
        $row = (clone $table)->where('user_id', $userId)->first();

        if ($row === null) {
            return null;
        }

        $tokenHash = (string) $row->token_hash;

        if (! hash_equals($tokenHash, AdminLoginLinks::hashToken($token))) {
            return null;
        }

        if (now()->greaterThanOrEqualTo($row->expires_at)) {
            $this->forget($userId, $tokenHash);

            return null;
        }

        $user = AdminLoginLinks::userProvider($panel)->retrieveById($userId);

        if (! $user instanceof Authenticatable || ! hash_equals((string) $row->password_fingerprint, AdminLoginLinks::passwordFingerprint($user))) {
            $this->forget($userId, $tokenHash);

            return null;
        }

        $refusal = AdminLoginLinks::refusalFor($user, $panel);

        if ($refusal instanceof AdminLoginLinkRefusal) {
            if ($consume) {
                $this->forget($userId, $tokenHash);
            }

            return $refusal;
        }

        if (! $consume) {
            return $user;
        }

        return $this->forget($userId, $tokenHash) === 1 ? $user : null;
    }

    private function forget(string $userId, string $tokenHash): int
    {
        return DB::table(AdminLoginLinks::table())
            ->where('user_id', $userId)
            ->where('token_hash', $tokenHash)
            ->delete();
    }
}
