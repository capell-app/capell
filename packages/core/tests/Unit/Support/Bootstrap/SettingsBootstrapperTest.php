<?php

declare(strict_types=1);

use Capell\Core\Data\PackageData;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Settings\CoreSettings;
use Capell\Core\Support\Bootstrap\SettingsBootstrapper;
use Capell\Core\Support\CapellCoreManager;
use Capell\Core\ThemeStudio\Settings\ThemeStudioSettings;
use Illuminate\Config\Repository;

it('loads Spatie defaults and aggregates Capell settings when configuration is uncached', function (): void {
    $app = Mockery::mock(app())->makePartial();
    $app->shouldReceive('configurationIsCached')->once()->andReturnFalse();
    $config = new Repository([
        'settings' => [
            'settings' => ['App\\Settings\\ExistingSettings'],
            'custom_value' => 'preserved',
        ],
    ]);
    $manager = Mockery::mock(CapellCoreManager::class);
    $manager->shouldReceive('getPackages')->once()->andReturn(collect([
        new PackageData('vendor/duplicate-existing', PackageTypeEnum::Plugin, setting: 'App\\Settings\\ExistingSettings'),
        new PackageData('vendor/duplicate-core', PackageTypeEnum::Plugin, setting: CoreSettings::class),
        new PackageData('vendor/blank', PackageTypeEnum::Plugin, setting: ''),
        new PackageData('vendor/none', PackageTypeEnum::Plugin),
        new PackageData('vendor/first', PackageTypeEnum::Plugin, setting: 'Vendor\\Settings\\FirstSettings'),
        new PackageData('vendor/duplicate-first', PackageTypeEnum::Plugin, setting: 'Vendor\\Settings\\FirstSettings'),
        new PackageData('vendor/second', PackageTypeEnum::Plugin, setting: 'Vendor\\Settings\\SecondSettings'),
    ]));
    $originalManager = CapellCore::getFacadeRoot();
    CapellCore::swap($manager);

    try {
        new SettingsBootstrapper($app, $config)->bootstrap();
    } finally {
        CapellCore::swap($originalManager);
    }

    expect($config->get('settings.default_repository'))->toBe('database')
        ->and($config->get('settings.custom_value'))->toBe('preserved')
        ->and($config->get('settings.settings'))->toBe([
            'App\\Settings\\ExistingSettings',
            CoreSettings::class,
            ThemeStudioSettings::class,
            'Vendor\\Settings\\FirstSettings',
            'Vendor\\Settings\\SecondSettings',
        ]);
});

it('preserves cached Spatie config while aggregating Capell settings', function (): void {
    $app = Mockery::mock(app())->makePartial();
    $app->shouldReceive('configurationIsCached')->once()->andReturnTrue();
    $config = new Repository([
        'settings' => [
            'settings' => ['App\\Settings\\CachedSettings'],
            'custom_cached_value' => 'preserved',
        ],
    ]);
    $manager = Mockery::mock(CapellCoreManager::class);
    $manager->shouldReceive('getPackages')->once()->andReturn(collect([
        new PackageData('vendor/duplicate-cached', PackageTypeEnum::Plugin, setting: 'App\\Settings\\CachedSettings'),
        new PackageData('vendor/duplicate-theme', PackageTypeEnum::Plugin, setting: ThemeStudioSettings::class),
        new PackageData('vendor/blank', PackageTypeEnum::Plugin, setting: ''),
        new PackageData('vendor/none', PackageTypeEnum::Plugin),
        new PackageData('vendor/first', PackageTypeEnum::Plugin, setting: 'Vendor\\Settings\\FirstSettings'),
        new PackageData('vendor/duplicate-first', PackageTypeEnum::Plugin, setting: 'Vendor\\Settings\\FirstSettings'),
        new PackageData('vendor/second', PackageTypeEnum::Plugin, setting: 'Vendor\\Settings\\SecondSettings'),
    ]));
    $originalManager = CapellCore::getFacadeRoot();
    CapellCore::swap($manager);

    try {
        new SettingsBootstrapper($app, $config)->bootstrap();
    } finally {
        CapellCore::swap($originalManager);
    }

    expect($config->get('settings'))
        ->not->toHaveKey('default_repository')
        ->and($config->get('settings.custom_cached_value'))->toBe('preserved')
        ->and($config->get('settings.settings'))->toBe([
            'App\\Settings\\CachedSettings',
            CoreSettings::class,
            ThemeStudioSettings::class,
            'Vendor\\Settings\\FirstSettings',
            'Vendor\\Settings\\SecondSettings',
        ]);
});
