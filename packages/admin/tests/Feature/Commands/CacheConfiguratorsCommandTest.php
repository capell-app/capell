<?php

declare(strict_types=1);

use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Enums\AdminSurfaceContributionType;
use Capell\Admin\Enums\ConfiguratorTypeEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Configurators\Languages\DefaultLanguageConfigurator;
use Capell\Admin\Filament\Plugin\CapellAdminPlugin;
use Capell\Admin\Support\AdminSurfaceContributionRegistry;
use Capell\Admin\Tests\Fixtures\Configurators\ExternalLanguageConfigurator;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\PanelRegistry;

beforeEach(function (): void {
    CapellAdmin::clearCachedConfigurators();
});

afterEach(function (): void {
    CapellAdmin::clearCachedConfigurators();
});

it('runs cache configurators command successfully', function (): void {
    artisanCommand('capell:admin-cache-configurators')
        ->assertExitCode(0);
});

it('caches the same external configurator override used while serving the admin panel', function (): void {
    $key = 'configurator:' . ConfiguratorTypeEnum::Language->value . ':' . DefaultLanguageConfigurator::getKey();

    CapellAdmin::serving(function (): void {
        $registry = resolve(AdminSurfaceContributionRegistry::class);
        $key = 'configurator:' . ConfiguratorTypeEnum::Language->value . ':' . DefaultLanguageConfigurator::getKey();
        $contributions = $registry->all()[AdminSurfaceContributionType::Configurator->value] ?? [];

        if (isset($contributions[$key])) {
            $registry->replace(AdminSurfaceContributionData::configurator(
                class: ExternalLanguageConfigurator::class,
                group: ConfiguratorTypeEnum::Language->value,
                name: DefaultLanguageConfigurator::getKey(),
            ));
        }
    });

    artisanCommand('capell:admin-cache-configurators')
        ->assertExitCode(0);

    $runtimeConfigurators = CapellAdmin::getConfigurators(ConfiguratorTypeEnum::Language->value);
    $cachedContributions = require CapellAdmin::getConfiguratorCachePath();

    expect($cachedContributions['configurator'][$key]['class'])
        ->toBe(ExternalLanguageConfigurator::class);

    CapellAdmin::clearAdminSurfaceContributions();
    CapellAdmin::restoreCachedConfigurators();

    expect(CapellAdmin::getConfigurators(ConfiguratorTypeEnum::Language->value))
        ->toBe($runtimeConfigurators)
        ->toBe([DefaultLanguageConfigurator::getKey() => ExternalLanguageConfigurator::class]);
});

it('selects the Capell-integrated panel when another panel is the default', function (): void {
    $defaultPanel = Panel::make()
        ->id('backoffice')
        ->default();
    $capellPanel = Panel::make()
        ->id('content')
        ->plugin(CapellAdminPlugin::make());

    $panelRegistry = resolve(PanelRegistry::class);
    $panelRegistry->panels = [
        $defaultPanel->getId() => $defaultPanel,
        $capellPanel->getId() => $capellPanel,
    ];
    $panelRegistry->defaultPanel = $defaultPanel;

    $servedPanelIds = [];

    CapellAdmin::serving(function () use (&$servedPanelIds): void {
        $panel = Filament::getCurrentPanel();

        if ($panel instanceof Panel) {
            $servedPanelIds[] = $panel->getId();
        }
    });

    artisanCommand('capell:admin-cache-configurators')
        ->assertExitCode(0);

    $runtimeConfigurators = CapellAdmin::getConfigurators(ConfiguratorTypeEnum::Language->value);
    $cachedContributions = require CapellAdmin::getConfiguratorCachePath();
    $key = 'configurator:' . ConfiguratorTypeEnum::Language->value . ':' . DefaultLanguageConfigurator::getKey();

    expect($servedPanelIds)
        ->toBe(['content'])
        ->and($cachedContributions['configurator'][$key]['class'])
        ->toBe(DefaultLanguageConfigurator::class);

    CapellAdmin::clearAdminSurfaceContributions();
    CapellAdmin::restoreCachedConfigurators();

    expect(CapellAdmin::getConfigurators(ConfiguratorTypeEnum::Language->value))
        ->toBe($runtimeConfigurators);
});

it('fails closed when no Capell-integrated panel is registered', function (): void {
    $panel = Panel::make()
        ->id('backoffice')
        ->default();

    $panelRegistry = resolve(PanelRegistry::class);
    $panelRegistry->panels = [$panel->getId() => $panel];
    $panelRegistry->defaultPanel = $panel;

    artisanCommand('capell:admin-cache-configurators')
        ->expectsOutput('Unable to cache configurators: no Filament panel is integrated with Capell Admin.')
        ->assertExitCode(1);

    expect(CapellAdmin::getConfiguratorCachePath())->not->toBeFile();
});
