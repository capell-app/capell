<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Capell\Admin\Contracts\Extenders\AdminPanelExtender;
use Capell\Admin\Filament\Plugin\CapellAdminPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Override;

/**
 * An installed package that adds panel authentication middleware while the panel is
 * constructed and owns a standalone route under the panel path, shaped like the
 * Marketplace callbacks: Filament authentication plus the plugin's request stack.
 */
final class BootAuthenticationRuntimeProvider extends ServiceProvider
{
    public const string ROUTE_NAME = 'runtime-boot-authentication.callback';

    #[Override]
    public function register(): void
    {
        $this->app->tag([BootAuthenticationPanelExtender::class], AdminPanelExtender::TAG);
    }

    public function boot(): void
    {
        Route::prefix('admin')
            ->middleware([
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
            ])
            ->group(function (): void {
                Route::get('/runtime-boot-authentication/callback', static fn (): string => 'callback reached')
                    ->name(self::ROUTE_NAME);
            });
    }
}
