<?php

declare(strict_types=1);

namespace Capell\Admin\Providers\Filament;

use Capell\Admin\Enums\FilamentColorEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Plugin\CapellAdminPlugin;
use Capell\Admin\Http\Middleware\AddAdminSecurityHeaders;
use Capell\Admin\Http\Middleware\ProfileAdminRequest;
use Capell\Admin\Http\Middleware\SetSitePermissionScope;
use Capell\Admin\Support\AdminPanelEntrypoint;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Widgets\FilamentInfoWidget;
use Filament\Widgets\Widget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Pboivin\FilamentPeek\FilamentPeekPlugin;
use Pboivin\FilamentPeek\FilamentPeekServiceProvider as BaseFilamentPeekServiceProvider;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        if (! $this->app->providerIsLoaded(BaseFilamentPeekServiceProvider::class)) {
            $this->app->register(BaseFilamentPeekServiceProvider::class);
        }

        /** @var list<class-string<Widget>> $widgets */
        $widgets = [
            ...CapellAdmin::getWidgets(),
            FilamentInfoWidget::class,
        ];

        return $panel
            ->default()
            ->id('admin')
            ->domain(AdminPanelEntrypoint::domain())
            ->path(AdminPanelEntrypoint::path())
            ->login()
            ->colors(FilamentColorEnum::colors())
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                CapellAdmin::getDashboardPage(),
            ])
            ->passwordReset()
            ->viteTheme('resources/css/filament/admin/theme.css', 'build/filament')
            ->navigationItems(CapellAdmin::getNavigationItems())
            ->navigationGroups(CapellAdmin::getNavigationGroups())
            ->plugin(CapellAdminPlugin::make()
                ->discoverConfigurators(in: app_path('Filament/Configurators'), for: 'App\\Filament\\Configurators'))
            ->plugin(FilamentPeekPlugin::make())
            ->sidebarFullyCollapsibleOnDesktop()
            ->widgets($widgets)
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                AddAdminSecurityHeaders::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                SetSitePermissionScope::class,
                ProfileAdminRequest::class,
            ]);
    }
}
