<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Auth;

use Capell\Admin\Enums\AdminLoginLinkRefusal;
use Capell\Admin\Notifications\AdminLoginLinkNotification;
use Capell\Admin\Support\Auth\AdminLoginLinks;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Timebox;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * Emails a sign-in link when someone asks for one from the login page.
 *
 * The caller always shows the same answer, whether or not the address belongs
 * to anyone, and the work runs inside a timebox so response times do not give
 * it away either. Each address receives at most a few links per window; extra
 * requests are dropped silently for the same reason.
 */
final class RequestAdminLoginLinkEmailAction
{
    use AsFake;
    use AsObject;

    /** Minimum time every request takes, in microseconds. */
    public const int TIMEBOX_MICROSECONDS = 400_000;

    /**
     * @return int|null Seconds until this IP address may ask again, or null when the request was accepted.
     */
    public function handle(string $email, string $ip, Panel $panel): ?int
    {
        $ipKey = 'capell-admin:login-link:ip:' . hash('sha256', $ip);

        if (RateLimiter::tooManyAttempts($ipKey, AdminLoginLinks::maxRequestsPerIp())) {
            return RateLimiter::availableIn($ipKey);
        }

        RateLimiter::hit($ipKey, AdminLoginLinks::requestWindowSeconds());

        resolve(Timebox::class)->call(function () use ($email, $panel): void {
            $this->sendIfAllowed(mb_strtolower(trim($email)), $panel);
        }, self::TIMEBOX_MICROSECONDS);

        return null;
    }

    private function sendIfAllowed(string $email, Panel $panel): void
    {
        if (! AdminLoginLinks::emailRequestsEnabled() || $email === '') {
            return;
        }

        $emailKey = 'capell-admin:login-link:email:' . hash('sha256', $email);

        if (RateLimiter::tooManyAttempts($emailKey, AdminLoginLinks::maxEmailsPerAddress())) {
            return;
        }

        RateLimiter::hit($emailKey, AdminLoginLinks::requestWindowSeconds());

        $user = AdminLoginLinks::userProvider($panel)->retrieveByCredentials(['email' => $email]);

        if (! $user instanceof Authenticatable || ! method_exists($user, 'notify')) {
            return;
        }

        if (AdminLoginLinks::refusalFor($user, $panel) instanceof AdminLoginLinkRefusal) {
            return;
        }

        try {
            $user->notify(new AdminLoginLinkNotification(IssueAdminLoginLinkAction::run($user, $panel)));
        } catch (Throwable $throwable) {
            // A failure only an existing account could cause must not change the answer the visitor sees.
            report($throwable);
        }
    }
}
