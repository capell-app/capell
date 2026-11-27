<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Auth;

use Capell\Admin\Enums\AdminLoginLinkRefusal;
use Capell\Admin\Filament\Pages\Auth\SignInWithLink;
use Capell\Admin\Support\Auth\AdminLoginLinks;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use LogicException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Creates a sign-in link for someone the panel admits. Issuing a link replaces
 * any earlier one, so only the newest link works. The token is returned only
 * inside the signed URL and is stored as a keyed hash.
 */
final class IssueAdminLoginLinkAction
{
    use AsFake;
    use AsObject;

    public function handle(Authenticatable $user, Panel $panel): string
    {
        throw_unless(AdminLoginLinks::enabled(), LogicException::class, 'Admin sign-in links are turned off.');
        throw_if(AdminLoginLinks::refusalFor($user, $panel) instanceof AdminLoginLinkRefusal, LogicException::class, 'This person cannot sign in with a link.');

        $token = bin2hex(random_bytes(32));
        $expiresAt = now()->addMinutes(AdminLoginLinks::expiresInMinutes());
        $userId = (string) $user->getAuthIdentifier();

        DB::table(AdminLoginLinks::table())->upsert(
            [[
                'user_id' => $userId,
                'token_hash' => AdminLoginLinks::hashToken($token),
                'password_fingerprint' => AdminLoginLinks::passwordFingerprint($user),
                'expires_at' => $expiresAt,
                'created_at' => now(),
            ]],
            ['user_id'],
            ['token_hash', 'password_fingerprint', 'expires_at', 'created_at'],
        );

        return URL::temporarySignedRoute(
            $panel->generateRouteName(SignInWithLink::ROUTE_NAME),
            $expiresAt,
            ['user' => $userId, 'token' => $token],
        );
    }
}
