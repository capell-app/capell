<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Extenders\AdminPanelExtender;
use Capell\Admin\Enums\SidebarCollapseEnum;
use Capell\Admin\Filament\Plugin\CapellAdminPlugin;
use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Admin\Settings\AdminSettings;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\TestAdminPanelExtender;
use Capell\Core\Facades\CapellCore;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;

use function Pest\Laravel\get;

beforeEach(function (): void {
    TestAdminPanelExtender::$called = false;
});

it('runs tagged admin panel extenders while registering the admin plugin', function (): void {
    app()->tag([TestAdminPanelExtender::class], AdminPanelExtender::TAG);

    CapellAdminPlugin::make()->register(Panel::make());

    expect(TestAdminPanelExtender::$called)->toBeTrue();
});

it('registers the admin tools dropdown in the topbar render hooks', function (): void {
    $panel = Panel::make();

    CapellAdminPlugin::make()->register($panel);

    $reflection = new ReflectionClass($panel);
    $renderHooks = $reflection->getProperty('renderHooks')->getValue($panel);

    expect($renderHooks)
        ->toHaveKey(PanelsRenderHook::GLOBAL_SEARCH_AFTER)
        ->and($renderHooks[PanelsRenderHook::GLOBAL_SEARCH_AFTER][''])
        ->toHaveCount(1);
});

it('starts a hidden-until-opened sidebar without the collapsed navigation rail', function (): void {
    $settings = resolve(AdminSettings::class);
    $settings->sidebar_collapsible = SidebarCollapseEnum::HiddenUntilOpened;
    $settings->save();

    app()->forgetInstance(AdminSettings::class);

    $panel = Panel::make();

    CapellAdminPlugin::make()->register($panel);

    expect($panel->isSidebarCollapsibleOnDesktop())->toBeFalse()
        ->and($panel->isSidebarFullyCollapsibleOnDesktop())->toBeTrue();

    $reflection = new ReflectionClass($panel);
    $renderHooks = $reflection->getProperty('renderHooks')->getValue($panel);
    $hooks = $renderHooks[PanelsRenderHook::HEAD_START][''];

    expect($hooks)->toHaveCount(1);

    $view = $hooks[0]();

    expect($view)->toBeInstanceOf(View::class)
        ->and($view->render())->toContain("window.localStorage.setItem('isOpen', 'false')")
        ->toContain("window.localStorage.setItem('isOpenDesktop', 'false')")
        ->toContain('.fi-topbar-open-sidebar-btn');
});

it('declares the shared Tailwind layer order before package styles load', function (): void {
    $panel = Panel::make();

    CapellAdminPlugin::make()->register($panel);

    $reflection = new ReflectionClass($panel);
    $renderHooks = $reflection->getProperty('renderHooks')->getValue($panel);
    $hooks = $renderHooks[PanelsRenderHook::STYLES_BEFORE][''];

    expect($hooks)->toHaveCount(1)
        ->and((string) $hooks[0]())->toContain(
            '<style data-capell-css-layer-order>@layer properties, theme, base, components, utilities;</style>',
        );
});

it('keeps the layer prelude on the uninstalled admin path', function (): void {
    CapellCore::forcePackageInstalled(AdminServiceProvider::$packageName, false);

    $panel = Panel::make();

    CapellAdminPlugin::make()->register($panel);

    $reflection = new ReflectionClass($panel);
    $renderHooks = $reflection->getProperty('renderHooks')->getValue($panel);
    $hooks = $renderHooks[PanelsRenderHook::STYLES_BEFORE][''];
    $renderedHooks = implode('', array_map(static fn (callable $hook): string => (string) $hook(), $hooks));

    expect($renderedHooks)->toContain(
        '<style data-capell-css-layer-order>@layer properties, theme, base, components, utilities;</style>',
    );
});

it('emits the layer prelude before the admin styles', function (): void {
    $html = get('/admin/login')->assertOk()->getContent();
    $preludePosition = strpos($html, 'data-capell-css-layer-order');
    $stylesPosition = strpos($html, ':root {');

    expect(substr_count($html, 'data-capell-css-layer-order'))->toBe(1)
        ->and($preludePosition)->toBeInt()
        ->and($stylesPosition)->toBeInt()
        ->and($preludePosition)->toBeLessThan($stylesPosition);
});
