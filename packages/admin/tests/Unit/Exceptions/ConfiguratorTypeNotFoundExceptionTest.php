<?php

declare(strict_types=1);

use Capell\Admin\Enums\ConfiguratorTypeEnum;
use Capell\Admin\Exceptions\ConfiguratorTypeNotFoundException;

it('includes key target and resource in missing type messages', function (): void {
    $exception = ConfiguratorTypeNotFoundException::forKey(
        'landing-page',
        ConfiguratorTypeEnum::Page,
        'default',
    );

    expect($exception->getMessage())
        ->toBe('Configurator type `landing-page` was not found for Page on resource `default`.');
});

it('includes target and resource in missing default messages', function (): void {
    $exception = ConfiguratorTypeNotFoundException::forDefault(
        ConfiguratorTypeEnum::Page,
        'default',
    );

    expect($exception->getMessage())
        ->toBe('Default configurator type was not found for Page on resource `default`.');
});
