<?php

declare(strict_types=1);

namespace Workbench\App\Support;

use Capell\Admin\Filament\Pages\Auth\SignInWithLink;
use Capell\Admin\Support\Auth\AdminLoginLinks;
use Illuminate\Support\Facades\URL;

final class LoginLinkScreenshotFixture
{
    /**
     * A correctly signed link whose token was never issued. It shows the page a
     * used or expired link lands on without minting a link that could sign
     * anyone in, which a guest-reachable fixture route must never do.
     */
    public static function usedLinkUrl(): string
    {
        return URL::temporarySignedRoute(
            AdminLoginLinks::panel()->generateRouteName(SignInWithLink::ROUTE_NAME),
            now()->addMinutes(AdminLoginLinks::expiresInMinutes()),
            ['user' => '0', 'token' => str_repeat('0', 64)],
        );
    }
}
