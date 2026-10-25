<?php

declare(strict_types=1);

use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Pages\CapellDashboard;
use Capell\Admin\Support\Bridges\AdminBridgeRegistry;
use Capell\Core\Contracts\AdminPanelUrlResolver;
use Capell\Core\Models\Site;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Capell\Installer\Bridges\InstallerAdminBridge;
use Capell\Installer\Filament\Pages\InstallCapellPage;
use Capell\Installer\Filament\Pages\InstallGuidePage;
use Capell\Installer\Filament\Pages\InstallProgressPage;
use Capell\Installer\Filament\Widgets\CapellNotInstalledFilamentWidget;
use Capell\Installer\Providers\InstallerAdminServiceProvider;
use Capell\Installer\Providers\InstallerServiceProvider;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Route;

it('registers the installer admin bridge through the admin-only provider', function (): void {
    $this->app->register(InstallerAdminServiceProvider::class);

    expect(resolve(AdminBridgeRegistry::class)->classes(InstallerServiceProvider::$packageName))
        ->toBe([InstallerAdminBridge::class])
        ->and(CapellAdmin::getAdminSurfaceRegistry()->pages())
        ->toContain(InstallCapellPage::class, InstallGuidePage::class, InstallProgressPage::class)
        ->and(CapellAdmin::getDashboardFilamentWidgets(DashboardEnum::NotInstalled))
        ->toContain(CapellNotInstalledFilamentWidget::class)
        ->and(FilamentView::hasRenderHook(
            PanelsRenderHook::PAGE_HEADER_WIDGETS_BEFORE,
            CapellDashboard::class,
        ))->toBeTrue();

    $receipts = collect(resolve(ExtensionContributionReceiptRegistry::class)->forPackage(InstallerServiceProvider::$packageName))
        ->where('providerBucket', 'admin');

    expect($receipts)->not->toBeEmpty()
        ->and($receipts->pluck('ownerPackage')->unique()->values()->all())
        ->toBe([InstallerServiceProvider::$packageName]);
});

it('keeps repeated admin provider registration idempotent', function (): void {
    $this->app->register(InstallerAdminServiceProvider::class, force: true);
    $this->app->register(InstallerAdminServiceProvider::class, force: true);

    expect(resolve(AdminBridgeRegistry::class)->classes(InstallerServiceProvider::$packageName))
        ->toBe([InstallerAdminBridge::class])
        ->and(CapellAdmin::getAdminSurfaceRegistry()->pages())
        ->toContain(InstallCapellPage::class, InstallGuidePage::class, InstallProgressPage::class)
        ->and(CapellAdmin::getDashboardFilamentWidgets(DashboardEnum::NotInstalled))
        ->toContain(CapellNotInstalledFilamentWidget::class);
});

it('resolves the configured panel independently of request host and current panel', function (string $path, array $domains, string $expected, bool $registerHome = true, bool $hasTenancy = false): void {
    config(['app.url' => 'https://site.test/capell', 'capell-admin.panel.id' => 'installed']);
    $panel = Panel::make()->id('installed')->path($path)->domains($domains);
    if ($hasTenancy) {
        $panel->tenant(Site::class);
        expect(Filament::getTenant())->toBeNull();
    }

    Filament::registerPanel($panel);
    Filament::registerPanel(Panel::make()->id('unrelated')->path('other'));
    Filament::setCurrentPanel(Filament::getPanel('unrelated'));
    $domainPrefix = count($domains) > 1 ? $domains[0] . '.' : '';
    if ($registerHome) {
        $homePath = $hasTenancy ? trim($path . '/{tenant}', '/') : $path;
        $route = Route::get($homePath, fn (): string => 'Panel home')
            ->name('filament.installed.' . $domainPrefix . 'home');
        if ($domains !== []) {
            $route->domain($domains[0]);
        }

        Route::getRoutes()->refreshNameLookups();
    }

    $urls = resolve(UrlGenerator::class);
    $urls->setRequest(Request::create('http://hostile.test/install?panel=unrelated'));
    $urls->forceRootUrl(null);
    $urls->forceScheme(null);

    $requestUrl = $urls->to('/unchanged');

    expect(resolve(AdminPanelUrlResolver::class)->resolve())->toBe($expected)
        ->and($urls->to('/unchanged'))->toBe($requestUrl);
    if ($hasTenancy) {
        expect(Filament::getTenant())->toBeNull();
    }
})->with([
    'default path' => ['admin', [], 'https://site.test/capell/admin'],
    'custom path' => ['cms', [], 'https://site.test/capell/cms'],
    'separate domain root' => ['', ['cms.test'], 'https://cms.test'],
    'separate domain path' => ['workspace', ['cms.test'], 'https://cms.test/workspace'],
    'multiple configured domains' => ['cms', ['cms.test', 'cms-alias.test'], 'https://cms.test/cms'],
    'panel without home route' => ['cms', [], 'https://site.test/capell/cms', false],
    'domain root without home route' => ['', ['cms.test'], 'https://cms.test', false],
    'tenant selection path' => ['cms', [], 'https://site.test/capell/cms', true, true],
    'tenant domain entry' => ['workspace', ['cms.test'], 'https://cms.test/workspace', true, true],
    'tenant domain root entry' => ['', ['cms.test'], 'https://cms.test', true, true],
    'tenant multi-domain entry' => ['cms', ['cms.test', 'cms-alias.test'], 'https://cms.test/cms', true, true],
    'tenant selection without home route' => ['cms', [], 'https://site.test/capell/cms', false, true],
]);
