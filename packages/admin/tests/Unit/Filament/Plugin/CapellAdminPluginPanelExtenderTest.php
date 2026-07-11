<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Extenders\AdminPanelExtender;
use Capell\Admin\Filament\Plugin\CapellAdminPlugin;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\TestAdminPanelExtender;
use Filament\Panel;
use Filament\View\PanelsRenderHook;

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
