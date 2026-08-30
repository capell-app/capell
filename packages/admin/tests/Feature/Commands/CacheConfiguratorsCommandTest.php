<?php

declare(strict_types=1);

use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Enums\AdminSurfaceContributionType;
use Capell\Admin\Enums\ConfiguratorTypeEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Configurators\Languages\DefaultLanguageConfigurator;
use Capell\Admin\Support\AdminSurfaceContributionRegistry;
use Capell\Admin\Tests\Fixtures\Configurators\ExternalLanguageConfigurator;

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
