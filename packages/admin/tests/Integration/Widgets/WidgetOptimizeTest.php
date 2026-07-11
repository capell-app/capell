<?php

declare(strict_types=1);

use Capell\Admin\Console\Commands\CacheConfiguratorsCommand;
use Capell\Admin\Console\Commands\CacheWidgetsCommand;
use Capell\Admin\Console\Commands\ClearConfiguratorsCacheCommand;
use Capell\Admin\Console\Commands\ClearWidgetsCacheCommand;
use Illuminate\Support\ServiceProvider;

it('configurator cache command is registered with laravel optimize', function (): void {
    expect(ServiceProvider::$optimizeCommands)
        ->toHaveKey('capell-admin-configurators')
        ->and(ServiceProvider::$optimizeCommands['capell-admin-configurators'])
        ->toBe(CacheConfiguratorsCommand::class);
});

it('configurator clear command is registered with laravel optimize:clear', function (): void {
    expect(ServiceProvider::$optimizeClearCommands)
        ->toHaveKey('capell-admin-configurators')
        ->and(ServiceProvider::$optimizeClearCommands['capell-admin-configurators'])
        ->toBe(ClearConfiguratorsCacheCommand::class);
});

it('widget cache command is registered with laravel optimize', function (): void {
    expect(ServiceProvider::$optimizeCommands)
        ->toHaveKey('capell-admin-widgets')
        ->and(ServiceProvider::$optimizeCommands['capell-admin-widgets'])
        ->toBe(CacheWidgetsCommand::class);
});

it('widget clear command is registered with laravel optimize:clear', function (): void {
    expect(ServiceProvider::$optimizeClearCommands)
        ->toHaveKey('capell-admin-widgets')
        ->and(ServiceProvider::$optimizeClearCommands['capell-admin-widgets'])
        ->toBe(ClearWidgetsCacheCommand::class);
});
