<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Extenders\AdminPanelExtender;
use Capell\Admin\Enums\SidebarCollapseEnum;
use Capell\Admin\Filament\Plugin\CapellAdminPlugin;
use Capell\Admin\Settings\AdminSettings;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\TestAdminPanelExtender;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;

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
