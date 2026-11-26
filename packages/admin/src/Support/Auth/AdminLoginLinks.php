<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Auth;

use Capell\Admin\Enums\AdminLoginLinkRefusal;
use Capell\Admin\Support\AdminPanelEntrypoint;
use Capell\Core\Support\TableName;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use LogicException;
use SensitiveParameter;

/**
 * Settings and rules shared by every step of an admin sign-in link: issuing,
 * emailing and redeeming. A link only ever stands in for the password, so a
 * person the panel would not admit, or who has two-factor authentication
 * turned on, is never given one.
 */
final class AdminLoginLinks
{
    public const int DEFAULT_EXPIRES_IN_MINUTES = 15;

    public static function enabled(): bool
    {
        return (bool) config('capell-admin.login_links.enabled', true);
    }

    public static function emailRequestsEnabled(): bool
    {
        return self::enabled() && (bool) config('capell-admin.login_links.email_requests', true);
    }

    public static function expiresInMinutes(): int
    {
        $minutes = config('capell-admin.login_links.expires_in_minutes');

        return is_numeric($minutes) && (int) $minutes > 0 ? (int) $minutes : self::DEFAULT_EXPIRES_IN_MINUTES;
    }

    public static function maxEmailsPerAddress(): int
    {
        return self::positiveInt('max_emails_per_address', 3);
    }

    public static function maxRequestsPerIp(): int
    {
        return self::positiveInt('max_requests_per_ip', 10);
    }

    public static function requestWindowSeconds(): int
    {
        return self::positiveInt('request_window_minutes', 15) * 60;
    }

    public static function table(): string
    {
        return TableName::resolve('admin_login_link_tokens');
    }

    public static function panel(): Panel
    {
        $panel = Filament::getCurrentPanel() ?? Filament::getPanel(AdminPanelEntrypoint::PANEL, isStrict: false);

        throw_unless($panel instanceof Panel, LogicException::class, 'Sign-in links need a Filament panel.');

        return $panel;
    }

    public static function userProvider(Panel $panel): UserProvider
    {
        $guard = $panel->auth();

        throw_unless($guard instanceof SessionGuard, LogicException::class, 'Sign-in links need a session guard.');

        return $guard->getProvider();
    }

    /** Why this person may not sign in with a link, or null when they may. */
    public static function refusalFor(Authenticatable $user, Panel $panel): ?AdminLoginLinkRefusal
    {
        if ($user instanceof FilamentUser && ! $user->canAccessPanel($panel)) {
            return AdminLoginLinkRefusal::NoPanelAccess;
        }

        foreach ($panel->getMultiFactorAuthenticationProviders() as $provider) {
            if ($provider->isEnabled($user)) {
                return AdminLoginLinkRefusal::TwoFactorEnabled;
            }
        }

        return null;
    }

    /**
     * Ties a link to the password the person had when it was issued, so any
     * password change, by them or anyone else, ends every link already sent.
     */
    public static function passwordFingerprint(Authenticatable $user): string
    {
        return hash_hmac('sha256', 'admin-login-link|' . $user->getAuthPassword(), self::key());
    }

    public static function hashToken(#[SensitiveParameter] string $token): string
    {
        return hash_hmac('sha256', $token, self::key());
    }

    private static function key(): string
    {
        $key = config('app.key');

        throw_unless(is_string($key) && $key !== '', LogicException::class, 'Sign-in links need an application key.');

        return $key;
    }

    private static function positiveInt(string $key, int $default): int
    {
        $value = config('capell-admin.login_links.' . $key);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }
}
