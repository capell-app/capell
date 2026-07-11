<?php

declare(strict_types=1);

use Capell\Admin\Enums\ConfiguratorTypeEnum;
use Capell\Admin\Filament\Configurators\Blueprints\PageBlueprintConfigurator;
use Capell\Admin\Filament\Configurators\Pages\DefaultPageConfigurator;

it('maps built-in configurator blueprints to configurator classes', function (): void {
    expect(ConfiguratorTypeEnum::Page->getConfigurators())
        ->toHaveKey('default', DefaultPageConfigurator::class)
        ->and(ConfiguratorTypeEnum::Blueprint->getConfigurators())
        ->toHaveKey('page', PageBlueprintConfigurator::class);
});
