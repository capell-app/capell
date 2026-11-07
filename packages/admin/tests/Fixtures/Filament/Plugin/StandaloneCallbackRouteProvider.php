<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Capell\Admin\Filament\Plugin\CapellAdminPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Owns a standalone route under the panel path shaped like the Marketplace callbacks:
 * the panel association, Filament authentication and the plugin's request stack.
 */
final class StandaloneCallbackRouteProvider extends ServiceProvider
{
    public const string URI = '/admin/standalone/callback';

    public const string BODY = 'callback reached';

    public static function callback(string $uri, string $name): RoutingRoute
    {
        return Route::middleware([
            'panel:admin',
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            AuthenticateSession::class,
            ShareErrorsFromSession::class,
            SubstituteBindings::class,
            DisableBladeIconComponents::class,
            DispatchServingFilamentEvent::class,
            Authenticate::class,
            ...CapellAdminPlugin::authMiddleware(),
        ])->get($uri, static fn (): string => self::BODY)->name($name);
    }

    public function boot(): void
    {
        self::callback(self::URI, 'runtime-standalone.callback');
    }
}
